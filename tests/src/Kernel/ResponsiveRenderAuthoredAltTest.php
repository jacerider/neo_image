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
use Drupal\neo_image\NeoImageUtility;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the responsive render carries a media's authored alt and title.
 *
 * The **responsive render** is `NeoImage` — `#theme => neo_image`. Built from
 * an **entity-sourced image** it is supposed to answer the **authored alt**,
 * the alt stored on the image field item the media's source declares. It did
 * not: `createFromEntity()` merged an `alt` default into the field value and
 * then read `title` for *both* fallbacks, so the authored alt was loaded and
 * discarded.
 *
 * **Why kernel and not unit.** The derivation asks a media for its source
 * field definition through its source plugin, and the second altitude these
 * criteria assert is rendered markup. A unit test could reach neither without
 * mocking `MediaInterface` and its source, which would assert the mock.
 *
 * **Two altitudes per criterion.** The built render array names the layer that
 * broke; the `<img>` the render pipeline produces is the promise the plan
 * makes. A criterion that can only make the second assertion is missing the
 * first.
 */
#[Group('neo_image')]
final class ResponsiveRenderAuthoredAltTest extends KernelTestBase {

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
   * The name of the file media type's source field — not an image field.
   */
  private string $fileSourceField;

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

    // A media type whose source field is a plain file field: it carries a
    // description and a display flag, and neither an alt nor a title.
    $fileType = $this->createMediaType('file', ['id' => 'document']);
    $this->fileSourceField = $fileType->getSource()->getConfiguration()['source_field'];

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
   * It renders the alt authored on a media as the image's alt.
   */
  public function testAuthoredAltReachesTheImage(): void {
    $media = $this->createImageMedia('Authored alt', '');

    $build = NeoImage::createFromEntity($media)->toRenderable();

    $this->assertSame('Authored alt', $build['#alt']);
    $this->assertStringContainsString('alt="Authored alt"', $this->renderImage($build));
  }

  /**
   * It renders the title authored on a media as the image's title.
   */
  public function testAuthoredTitleReachesTheImage(): void {
    $media = $this->createImageMedia('Authored alt', 'Authored title');

    $build = NeoImage::createFromEntity($media)->toRenderable();

    $this->assertSame('Authored title', $build['#title']);
    $this->assertStringContainsString('title="Authored title"', $this->renderImage($build));
  }

  /**
   * It prefers a supplied alt over the authored one.
   */
  public function testSuppliedAltWinsOverTheAuthoredOne(): void {
    $media = $this->createImageMedia('Authored alt', 'Authored title');

    $build = NeoImage::createFromEntity($media, 'Supplied alt', 'Supplied title')->toRenderable();

    $this->assertSame('Supplied alt', $build['#alt']);
    $this->assertSame('Supplied title', $build['#title']);
    $markup = $this->renderImage($build);
    $this->assertStringContainsString('alt="Supplied alt"', $markup);
    $this->assertStringContainsString('title="Supplied title"', $markup);
  }

  /**
   * It treats an empty supplied alt as unsupplied and derives instead.
   *
   * Both fallbacks the **responsive render** owns are driven here, because
   * both are on the path every Twig call site takes: `neo_image(media)` builds
   * with `createFromEntity()` and then calls `toRenderable()` with the empty
   * string its own argument defaults to. A fallback that only catches NULL is
   * inert on exactly that path.
   */
  public function testEmptySuppliedAltIsUnsupplied(): void {
    $media = $this->createImageMedia('Authored alt', 'Authored title');

    $build = NeoImage::createFromEntity($media, '', '')->toRenderable('', '');

    $this->assertSame('Authored alt', $build['#alt']);
    $this->assertSame('Authored title', $build['#title']);
    $markup = $this->renderImage($build);
    $this->assertStringContainsString('alt="Authored alt"', $markup);
    $this->assertStringContainsString('title="Authored title"', $markup);
  }

  /**
   * It derives nothing from the three subjects that have nothing to give.
   *
   * A bare file entity, an image media whose alt was left blank, and a media
   * whose source field is not an image field. None of the three is an error,
   * and none of them invents a filename or a media label. The `alt=""` those
   * cases end up with is ticket 03's floor, not this ticket's answer, so the
   * assertions stop at the derivation and the render array it feeds.
   */
  public function testNothingIsDerivedWhereNothingIsAuthored(): void {
    $blank = $this->createImageMedia('', '');
    $document = $this->createFileMedia();

    $this->assertSame(['alt' => NULL, 'title' => NULL], NeoImageUtility::authoredAltAndTitle($this->file));
    $this->assertSame(['alt' => NULL, 'title' => NULL], NeoImageUtility::authoredAltAndTitle($blank));
    $this->assertSame(['alt' => NULL, 'title' => NULL], NeoImageUtility::authoredAltAndTitle($document));

    foreach ([$this->file, $blank, $document] as $subject) {
      $build = NeoImage::createFromEntity($subject)->toRenderable();
      $this->assertNull($build['#alt']);
      $this->assertNull($build['#title']);
    }
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
   * Creates a media item whose source field is not an image field.
   */
  private function createFileMedia(): MediaInterface {
    $media = Media::create([
      'bundle' => 'document',
      'name' => 'File media',
      $this->fileSourceField => [
        'target_id' => $this->file->id(),
        'description' => 'Not an alt',
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
