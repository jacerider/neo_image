<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the URL entry points to answering an SVG's own file URL.
 *
 * An SVG is an **unstyleable source**: no toolkit core ships can read one, so
 * a derivative is never written and a style URL for one answers an error. The
 * single-style render already emitted the file as-is; the two URL **entry
 * points** built a style URL regardless, which is what a template reaches
 * through `neo_image_style_url()` — a hero's logo `<img src>`, a footer
 * crest's `background-image`. They now answer the file's own URL instead.
 *
 * **What does not move.** A raster still answers its style URL through both
 * entry points, and an external URL is still answered unchanged. A private
 * SVG reaches the entity entry point as a routable `/system/files` URL, and
 * the URI entry point still answers every non-public stream unchanged, so
 * `docs/adr/0011`'s asymmetry is not touched for any file.
 *
 * **Why kernel.** Every answer is built from a stream wrapper, the public
 * files base path, or an image style's own URL, and two subjects are
 * entities.
 */
#[Group('neo_image')]
final class SvgUrlPassthroughTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * The options every subject is styled under.
   */
  private const OPTIONS = ['scale' => ['width' => 100]];

  /**
   * The SVG every public fixture is built on.
   */
  private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'neo_settings',
    'neo_twig',
    'neo_image',
  ];

  /**
   * The public SVG file entity.
   */
  private FileInterface $svg;

  /**
   * The public raster file entity, the control.
   */
  private FileInterface $png;

  /**
   * The name of the image media type's source field.
   */
  private string $imageSourceField;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);

    $imageType = $this->createMediaType('image', ['id' => 'image']);
    $this->imageSourceField = $imageType->getSource()->getConfiguration()['source_field'];

    $this->svg = $this->createSvgFixture('public://logo.svg');

    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      'public://image-test.png',
      FileExists::Replace
    );
    $png = File::create(['uri' => 'public://image-test.png']);
    $png->setPermanent();
    $png->save();
    $this->png = $png;
  }

  /**
   * An SVG answers its own file URL through both entry points.
   *
   * Every spelling a template can hand the Twig function is asserted: the
   * stream URI, the root-relative web path an image prop's `src` actually
   * is, an uppercase extension, a query string, the file entity and the
   * image media pointing at it.
   */
  public function testAnSvgAnswersItsOwnFileUrl(): void {
    $expected = $this->fileUrl('public://logo.svg');
    $webPath = '/' . PublicStream::basePath() . '/logo.svg';

    $subjects = [
      'a public:// uri' => 'public://logo.svg',
      'a public-files web path' => $webPath,
      'a file entity' => $this->svg,
      'an image media' => $this->createImageMedia($this->svg),
    ];
    foreach ($subjects as $name => $subject) {
      $answer = TwigExtension::renderImageStyleUrl($subject, self::OPTIONS);
      $this->assertSame($expected, $answer, $name . ' answers the file url.');
      $this->assertStringNotContainsString('/styles/', $answer, $name . ' answers no style url.');
    }

    $upper = $this->createSvgFixture('public://LOGO.SVG');
    $this->assertSame(
      $this->fileUrl('public://LOGO.SVG'),
      (new NeoImageStyle(self::OPTIONS))->toUrlFromEntity($upper),
      'The extension is matched case-insensitively.'
    );
    $this->assertStringNotContainsString(
      '/styles/',
      TwigExtension::renderImageStyleUrl($webPath . '?v=2', self::OPTIONS),
      'A query string does not hide the extension.'
    );
  }

  /**
   * A private SVG answers a routable URL from the entity entry point.
   *
   * The URI entry point is asserted beside it to say that ADR 0011's
   * asymmetry stands: a non-public stream string is still answered unchanged.
   */
  public function testPrivateSvgFollowsTheEntryPointsAsymmetry(): void {
    $private = $this->createSvgFixture('private://logo.svg');
    $style = new NeoImageStyle(self::OPTIONS);

    $answer = $style->toUrlFromEntity($private);
    $this->assertSame($this->fileUrl('private://logo.svg'), $answer);
    $this->assertStringContainsString('/system/files/logo.svg', $answer);
    $this->assertSame('private://logo.svg', $style->toUrlFromUri('private://logo.svg'));
  }

  /**
   * A raster and an external URL answer exactly what they did.
   */
  public function testNothingElseMoves(): void {
    $style = new NeoImageStyle(self::OPTIONS);
    $styleUrl = $style->getImageStyle()->buildUrl('public://image-test.png');
    $this->assertStringContainsString('/styles/', $styleUrl);

    $this->assertSame($styleUrl, $style->toUrlFromUri('public://image-test.png'));
    $this->assertSame($styleUrl, $style->toUrlFromEntity($this->png));
    $this->assertSame($styleUrl, $style->toUrlFromEntity($this->createImageMedia($this->png)));
    $this->assertSame(
      'https://example.com/logo.svg',
      $style->toUrlFromUri('https://example.com/logo.svg'),
      'An external SVG is answered unchanged, as every external URL is.'
    );
  }

  /**
   * The predicate reads the path, not the query or fragment.
   */
  public function testTheSvgPredicate(): void {
    $this->assertTrue(NeoImageStyle::isSvgUri('public://a/logo.svg'));
    $this->assertTrue(NeoImageStyle::isSvgUri('/files/logo.SVG?itok=abc'));
    $this->assertTrue(NeoImageStyle::isSvgUri('logo.svg#view'));
    $this->assertFalse(NeoImageStyle::isSvgUri('public://logo.svg.png'));
    $this->assertFalse(NeoImageStyle::isSvgUri('public://svg/logo.png'));
    $this->assertFalse(NeoImageStyle::isSvgUri(''));
  }

  /**
   * The URL an SVG is served at, as the entry points must answer it.
   */
  private function fileUrl(string $uri): string {
    return $this->container->get('file_url_generator')->generateAbsoluteString($uri);
  }

  /**
   * Writes an SVG to a URI and saves a permanent file entity for it.
   */
  private function createSvgFixture(string $uri): FileInterface {
    \file_put_contents($uri, self::SVG);
    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  /**
   * Creates an image media item pointing at a file.
   */
  private function createImageMedia(FileInterface $file): MediaInterface {
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Image media',
      $this->imageSourceField => [
        'target_id' => $file->id(),
        'alt' => 'Authored alt',
      ],
    ]);
    $media->save();
    return $media;
  }

}
