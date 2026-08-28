<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImage;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the single-style render carries a media's authored alt and title.
 *
 * The **single-style render** is `NeoImageStyle` — `#theme =>
 * neo_image_style`. It is the render behind thirty-three of the module's
 * forty-one Twig call sites, and built from an **entity-sourced image** it
 * never read the media at all: it resolved the thumbnail file and emitted
 * precisely the alt and title it was handed. Because every Twig entry point
 * defaults its alt argument to the empty string, a template passing a media
 * and no alt got `alt=""` on an image whose alt text sat one field away.
 *
 * **The same derivation, not a second copy.** Ticket 01 put
 * `NeoImageUtility::authoredAltAndTitle()` in the package for both renders to
 * call. The equality asserted here is the point of the ticket: the two layers
 * were allowed to disagree once already, and commit `5309c66` fixed only one
 * of them.
 *
 * **Why kernel and not unit.** The derivation asks a media for its source
 * field definition through its source plugin, and the second altitude these
 * criteria assert is rendered markup. A unit test could reach neither without
 * mocking `MediaInterface` and its source, which would assert the mock.
 */
#[Group('neo_image')]
final class SingleStyleRenderAuthoredAltTest extends KernelTestBase {

  use MediaTypeCreationTrait;

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
    // `neo-image.html.twig` uses `neo_attributes`, a filter `neo_twig`
    // registers. Twig parses the whole template whichever branch renders, so
    // the filter has to exist even for the single-source `<img>` output.
    'neo_twig',
    'neo_image',
  ];

  /**
   * The file every fixture media points at.
   */
  private FileInterface $file;

  /**
   * The name of the image media type's source field.
   */
  private string $imageSourceField;

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

    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      'public://image-test.png',
      FileExists::Replace
    );
    $file = File::create(['uri' => 'public://image-test.png']);
    $file->setPermanent();
    $file->save();
    $this->file = $file;
  }

  /**
   * It renders the alt authored on a media through the single-style render.
   */
  public function testAuthoredAltReachesTheImage(): void {
    $media = $this->createImageMedia('Authored alt', '');

    $build = (new NeoImageStyle([]))->toRenderableFromEntity($media);

    $this->assertSame('Authored alt', $build['#alt']);
    $this->assertStringContainsString('alt="Authored alt"', $this->renderImage($build));
  }

  /**
   * It renders the title authored on a media through the same render.
   */
  public function testAuthoredTitleReachesTheImage(): void {
    $media = $this->createImageMedia('Authored alt', 'Authored title');

    $build = (new NeoImageStyle([]))->toRenderableFromEntity($media);

    $this->assertSame('Authored title', $build['#title']);
    $this->assertStringContainsString('title="Authored title"', $this->renderImage($build));
  }

  /**
   * It answers the responsive render's alt through both twig entry points.
   *
   * `neo_image_style(media)` is the single-style render, and `neo_image(media)`
   * without breakpoint keys is dispatched to exactly the same place, so the
   * two functions have to agree with each other and with the **responsive
   * render** they are an alternative to. Both defaults their alt argument to
   * the empty string, which is what made the disagreement invisible.
   */
  public function testBothTwigEntryPointsAnswerTheResponsiveAlt(): void {
    $media = $this->createImageMedia('Authored alt', 'Authored title');

    $responsive = NeoImage::createFromEntity($media)->toRenderable();
    $style = TwigExtension::renderImageStyle($media);
    $image = TwigExtension::renderImage($media);

    $this->assertSame('Authored alt', $responsive['#alt']);
    $this->assertSame($responsive['#alt'], $style['#alt']);
    $this->assertSame($responsive['#alt'], $image['#alt']);
    $this->assertSame($responsive['#title'], $style['#title']);
    $this->assertSame($responsive['#title'], $image['#title']);

    $this->assertStringContainsString('alt="Authored alt"', $this->renderImage($style));
    $this->assertStringContainsString('alt="Authored alt"', $this->renderImage($image));
  }

  /**
   * It prefers a supplied alt and treats an empty supplied alt as unsupplied.
   */
  public function testSuppliedAltWinsAndEmptyMeansUnsupplied(): void {
    $media = $this->createImageMedia('Authored alt', 'Authored title');

    $supplied = (new NeoImageStyle([]))->toRenderableFromEntity($media, 'Supplied alt', 'Supplied title');
    $this->assertSame('Supplied alt', $supplied['#alt']);
    $this->assertSame('Supplied title', $supplied['#title']);
    $markup = $this->renderImage($supplied);
    $this->assertStringContainsString('alt="Supplied alt"', $markup);
    $this->assertStringContainsString('title="Supplied title"', $markup);

    $empty = (new NeoImageStyle([]))->toRenderableFromEntity($media, '', '');
    $this->assertSame('Authored alt', $empty['#alt']);
    $this->assertSame('Authored title', $empty['#title']);
  }

  /**
   * It leaves a uri-string subject rendering exactly as it does today.
   *
   * A string has nothing to derive from, so the emptiness rule must not reach
   * it: `neo_image_style(uri)` keeps emitting the honest `alt=""` its own
   * argument default produces, and the render method called with no alt keeps
   * answering NULL. Applying a fallback where there is no fallback is the
   * cheapest wrong way to implement this ticket.
   */
  public function testUriStringSubjectIsUnchanged(): void {
    $twig = TwigExtension::renderImageStyle('public://image-test.png');
    $this->assertSame('', $twig['#alt']);
    $this->assertSame('', $twig['#title']);
    $this->assertStringContainsString('alt=""', $this->renderImage($twig));

    $direct = (new NeoImageStyle([]))->toRenderableFromUri('public://image-test.png');
    $this->assertNull($direct['#alt']);
    $this->assertNull($direct['#title']);

    $supplied = TwigExtension::renderImageStyle('public://image-test.png', [], 'Supplied alt');
    $this->assertSame('Supplied alt', $supplied['#alt']);
    $this->assertStringContainsString('alt="Supplied alt"', $this->renderImage($supplied));
  }

  /**
   * Creates an image media item carrying the given authored alt and title.
   */
  private function createImageMedia(string $alt, string $title): MediaInterface {
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Image media',
      $this->imageSourceField => [
        'target_id' => $this->file->id(),
        'alt' => $alt,
        'title' => $title,
      ],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Renders a build and returns its markup.
   */
  private function renderImage(array $build): string {
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

}
