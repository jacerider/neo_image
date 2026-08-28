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
 * Tests that every `<img>` neo_image emits carries an alt attribute.
 *
 * The **alt floor**: an `<img>` with no `alt` attribute is a defect an audit
 * reports, while `alt=""` is a decorative image — a claim a reader can act on.
 * Two gaps sat under it. A NULL alt was assigned to the image element as NULL
 * and Drupal's image preprocess skips a NULL, so the attribute never appeared.
 * And the **unstyleable source** branch — an external URL, an animated GIF or
 * an SVG — built its image element and returned *before* alt and title were
 * assigned at all, so a caller's alt was discarded on the way past. That
 * second gap is the one live on this site: the header component defaults its
 * logo to an SVG and passes an alt on every render.
 *
 * **The floor is applied once, at the image element.** Both renders converge
 * there — the **responsive render**'s controlling image is a **single-style
 * render** — so one normalisation covers every `<img>` the module emits.
 * `<source>` elements take no alt and are untouched.
 *
 * **Why kernel and not unit.** The floor is a property of rendered markup and
 * the fixtures are real media entities; a unit test could reach neither
 * without mocking the render pipeline, which would assert the mock.
 */
#[Group('neo_image')]
final class AltFloorTest extends KernelTestBase {

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

    $this->writeUnstyleableFixtures();
  }

  /**
   * It emits an alt attribute on every image, empty when none is supplied.
   *
   * Asserted at two altitudes, and the two answer different questions. The
   * builders keep answering NULL, because no signature and no default changes
   * here and a string subject still has nothing to derive from; the floor is
   * the render's, not the builder's. The markup is the promise.
   */
  public function testEveryImageEmitsAnAltAttribute(): void {
    $media = $this->createImageMedia('', '');

    $subjects = [
      'a uri string' => (new NeoImageStyle([]))->toRenderableFromUri('public://image-test.png'),
      'an entity with no authored alt' => (new NeoImageStyle([]))->toRenderableFromEntity($media),
      'the responsive render' => NeoImage::createFromEntity($media)->toRenderable(),
      'an unstyleable source' => (new NeoImageStyle(['scale' => ['width' => 30]]))->toRenderableFromUri('public://logo.svg'),
    ];

    foreach ($subjects as $subject => $build) {
      $this->assertNull($build['#alt'], "The builder invents no alt for {$subject}.");
      $this->assertStringContainsString('alt=""', $this->renderImage($build), "The image rendered from {$subject} carries an alt attribute.");
    }
  }

  /**
   * It carries a supplied alt and title onto every unstyleable source.
   *
   * Three fixtures and not one. An external URL, an animated GIF and an SVG
   * reach the same branch by three different tests — a scheme check, a frame
   * count and a file extension — and a fix that only moves one of them is a
   * fix that gets re-broken by the next person to touch the other two.
   */
  public function testSuppliedAltAndTitleReachAnUnstyleableSource(): void {
    foreach ($this->unstyleableSources() as $subject => $uri) {
      $build = (new NeoImageStyle(['scale' => ['width' => 30]]))->toRenderableFromUri($uri, 'Supplied alt', 'Supplied title');

      $this->assertSame('Supplied alt', $build['#alt'], "The builder carries the alt for {$subject}.");
      $this->assertSame('Supplied title', $build['#title'], "The builder carries the title for {$subject}.");

      $markup = $this->renderImage($build);
      $this->assertStringContainsString('alt="Supplied alt"', $markup, "The image rendered from {$subject} carries the supplied alt.");
      $this->assertStringContainsString('title="Supplied title"', $markup, "The image rendered from {$subject} carries the supplied title.");
    }
  }

  /**
   * It leaves an unstyleable source otherwise exactly as it emits it today.
   *
   * The whole `<img>` is asserted, not a substring, because "otherwise
   * unchanged" is a statement about everything else on the element. The
   * dimensions decision stands: the declared width is emitted and the
   * unspecified axis is left unset, so the browser derives the height from the
   * image's intrinsic aspect ratio rather than being handed a square. The URI
   * is the source as-is, not a style derivative — which is the reason the
   * branch exists. And nothing else joins them.
   */
  public function testUnstyleableSourceIsOtherwiseUnchanged(): void {
    $fileUrlGenerator = $this->container->get('file_url_generator');

    foreach ($this->unstyleableSources() as $subject => $uri) {
      $build = (new NeoImageStyle(['scale' => ['width' => 30]]))->toRenderableFromUri($uri, 'Supplied alt');

      $src = str_starts_with($uri, 'public://')
        ? $fileUrlGenerator->generateString($uri)
        : $uri;
      $this->assertSame(
        '<img src="' . $src . '" width="30" alt="Supplied alt" />',
        trim($this->renderImage($build)),
        "The image rendered from {$subject} is unchanged but for its alt."
      );
    }
  }

  /**
   * It leaves an empty title omitted rather than emitting `title=""`.
   *
   * Title gets no floor. An empty title stays an omitted attribute where it is
   * one today, and stays an emitted one where it is that: `title=""` is
   * cosmetic noise rather than an accessibility defect, and either change
   * would put a markup diff in front of thirty sites for no stated benefit.
   * An unstyleable source is where the temptation lives, because it is where
   * the title assignment is new — it must arrive carrying titles and not
   * carrying empty ones.
   */
  public function testAnEmptyTitleStaysOmitted(): void {
    foreach ($this->unstyleableSources() as $subject => $uri) {
      // The Twig entry point, whose title argument defaults to the empty
      // string, which is the shape every call site on this site has.
      $twig = $this->renderImage(TwigExtension::renderImageStyle($uri, ['scale' => ['width' => 30]], 'Supplied alt'));
      $this->assertStringContainsString('alt="Supplied alt"', $twig, "The image rendered from {$subject} carries the supplied alt.");
      $this->assertStringNotContainsString('title=', $twig, "The image rendered from {$subject} omits its empty title.");

      // And the render method, whose title argument defaults to NULL.
      $direct = $this->renderImage((new NeoImageStyle(['scale' => ['width' => 30]]))->toRenderableFromUri($uri, 'Supplied alt'));
      $this->assertStringNotContainsString('title=', $direct, "The image rendered directly from {$subject} omits its absent title.");
    }

    // The styled branches keep emitting the empty title they emit today. The
    // floor being added is alt's alone.
    $styled = $this->renderImage(TwigExtension::renderImageStyle('public://image-test.png'));
    $this->assertStringContainsString('title=""', $styled);
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
   * The three sources no image style can derive a thumbnail from.
   *
   * @return array<string, string>
   *   The URI of each, keyed by what makes it unstyleable.
   */
  private function unstyleableSources(): array {
    return [
      'an external url' => 'https://example.com/remote.png',
      'an animated gif' => 'public://animated.gif',
      'an svg' => 'public://logo.svg',
    ];
  }

  /**
   * Writes the three sources no image style can derive a thumbnail from.
   *
   * An external URL needs no file. The SVG and the animated GIF do: the SVG is
   * matched by name but is served as-is, and the animated-GIF test reads the
   * file's frame headers, so a single-frame GIF — which is every GIF core
   * ships as a fixture — would not reach the branch at all.
   */
  private function writeUnstyleableFixtures(): void {
    \file_put_contents('public://logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>');
    // A two-frame 1x1 GIF89a: header, logical screen descriptor, a two-colour
    // global colour table, the NETSCAPE looping extension, then twice over a
    // graphic control extension, an image descriptor and one block of LZW
    // data. Two frame headers is what makes it animated.
    // A graphic control extension, an image descriptor and one block of LZW
    // data — one frame.
    $frame = '21f904000a0000002c0000000001000100000202440100';
    $gif = [
      // "GIF89a", then a 1x1 logical screen with a two-colour global table.
      '47494638396101000100800000000000ffffff',
      // The NETSCAPE2.0 looping application extension.
      '21ff0b4e45545343415045322e300301000000',
      // Two frames — two frame headers is what makes the GIF animated — and
      // the trailer.
      $frame . $frame . '3b',
    ];
    \file_put_contents('public://animated.gif', (string) \hex2bin(implode('', $gif)));
  }

  /**
   * Renders a build and returns its markup.
   */
  private function renderImage(array $build): string {
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

}
