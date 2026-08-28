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
use Drupal\neo_image\NeoImageUtility;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the one resolved file behind every entry point, and its contracts.
 *
 * The **resolved file** is the single step that answers *given a media or a
 * file, which file do I build this image from?* — a media answers its
 * thumbnail, a file answers itself, and anything else answers nothing. It
 * never throws.
 *
 * Four **entry points** used to answer that question privately, and no two of
 * the four agreed on what happens when the answer is nothing. They now all
 * delegate, and the **failure contract** of each is a documented choice of
 * which there are three rather than four, one per return shape: a factory
 * throws because it must answer an object, a render answers an empty render
 * array, a URL answers `'#'`. The media URL builder is the fourth behaviour —
 * a URL entry point wearing a factory's contract — and it keeps its throw
 * here, because ticket 03 retires it by label rather than by edit.
 *
 * **The contract table is the test.** One subject with no file, four asserted
 * answers. A table nobody asserts is a comment.
 *
 * **Why kernel and not unit.** Every subject is a media entity resolved
 * through its source plugin and its thumbnail reference. A unit test of the
 * resolver could only assert a mock of `MediaInterface`, which asserts the
 * mock.
 */
#[Group('neo_image')]
final class ResolvedFileContractTest extends KernelTestBase {

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

    $this->file = $this->createFileFixture('public://image-test.png');
  }

  /**
   * It resolves a media to its thumbnail file and a file entity to itself.
   *
   * Three of the plan's four fixtures answer here, and all three answer a
   * file. A media whose source field is not an image field is the one worth
   * spelling out: it is not an error and it is not nothing — its source gave
   * it a thumbnail like any other media, and the thumbnail is what the
   * resolver answers, exactly as all four entry points already did.
   */
  public function testItResolvesMediaToThumbnailAndFileToItself(): void {
    $media = $this->createImageMedia();

    $resolved = NeoImageUtility::resolvedFile($media);
    $this->assertInstanceOf(FileInterface::class, $resolved);
    $this->assertSame($this->file->id(), $resolved->id());
    $this->assertSame($this->file->getFileUri(), $resolved->getFileUri());

    $bare = NeoImageUtility::resolvedFile($this->file);
    $this->assertInstanceOf(FileInterface::class, $bare);
    $this->assertSame($this->file->id(), $bare->id());

    $fileMedia = $this->createFileMedia();
    $document = NeoImageUtility::resolvedFile($fileMedia);
    $this->assertInstanceOf(FileInterface::class, $document);
    $this->assertSame($fileMedia->get('thumbnail')->target_id, $document->id());
  }

  /**
   * It answers nothing, rather than throwing, when there is no file.
   *
   * "No file" is an entity-level statement and nothing more: the reference is
   * absent, or it is not a file. The resolver does not stat the disk — the
   * fixture's file is deleted as an *entity*, and the resolver would answer
   * the same way if the bytes were still on disk.
   */
  public function testItAnswersNothingRatherThanThrowingWhenThereIsNoFile(): void {
    $gone = $this->createMediaWithMissingThumbnail();

    $this->assertNull(NeoImageUtility::resolvedFile($gone));
  }

  /**
   * It answers each entry point's documented contract for a subject with none.
   *
   * The contract table, asserted. One subject the resolver answers nothing
   * for, and the four answers a caller can now read in four docblocks instead
   * of four implementations.
   */
  public function testEveryEntryPointKeepsItsDocumentedFailureContract(): void {
    $gone = $this->createMediaWithMissingThumbnail();
    $this->assertNull(NeoImageUtility::resolvedFile($gone), 'The subject is one the resolver answers nothing for.');

    $style = new NeoImageStyle([]);

    // A factory throws, because it must answer an object and has none.
    try {
      NeoImage::createFromEntity($gone);
      $this->fail('The responsive render factory throws when there is no file.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertNotSame('', $e->getMessage());
    }

    // A render answers an empty render array, which renders nothing.
    $this->assertSame([], $style->toRenderableFromEntity($gone));

    // A URL answers '#'.
    $this->assertSame('#', $style->toUrlFromEntity($gone));

    // The fourth behaviour: the media URL builder throws where a URL entry
    // point answers '#'. It keeps that throw, and ticket 03 deprecates it.
    $this->expectException(\InvalidArgumentException::class);
    $style->buildUrlForMedia($gone);
  }

  /**
   * It throws from the factory for the resolver's answer alone.
   *
   * The one behaviour change this ticket ships. The factory used to throw
   * twice, and its first throw fired on a missing *source field item* while
   * saying "does not have a file" — a message about a lookup it does not
   * perform, left behind by the alt read. A media with an empty source field
   * and a perfectly good thumbnail now renders that thumbnail.
   */
  public function testTheFactoryThrowsForTheResolverAnswerAlone(): void {
    $media = $this->createMediaWithNoSourceItem();
    $this->assertTrue($media->get($this->imageSourceField)->isEmpty(), 'The subject has no source field item.');

    $resolved = NeoImageUtility::resolvedFile($media);
    $this->assertInstanceOf(FileInterface::class, $resolved);
    $this->assertSame($this->file->getFileUri(), $resolved->getFileUri());

    $this->assertSame(
      $this->file->getFileUri(),
      NeoImage::createFromEntity($media)->getUri(),
      'The factory answers the thumbnail rather than throwing about the source field.'
    );

    $build = (new NeoImageStyle([]))->toRenderableFromEntity($media);
    $this->assertSame($this->file->getFileUri(), $build['#uri']);
  }

  /**
   * It leaves every entry point's answer unchanged when the file is present.
   *
   * Stated as the equality the extraction claims: each entry point answers
   * what the resolved file yields, for a media and for a bare file alike. A
   * pin written against hardcoded strings would pass an implementation that
   * quietly resolved somewhere else.
   */
  public function testEveryEntryPointAnswersTheResolvedFile(): void {
    $media = $this->createImageMedia();
    $style = new NeoImageStyle([]);

    foreach (['a media' => $media, 'a bare file' => $this->file] as $subject => $entity) {
      $resolved = NeoImageUtility::resolvedFile($entity);
      $this->assertInstanceOf(FileInterface::class, $resolved, "The resolver answers a file for {$subject}.");
      $expectedUrl = $style->getImageStyle()->buildUrl($resolved->getFileUri());

      $this->assertSame($resolved->getFileUri(), NeoImage::createFromEntity($entity)->getUri(), "The factory builds from the resolved file for {$subject}.");
      $this->assertSame($resolved->getFileUri(), $style->toRenderableFromEntity($entity)['#uri'], "The render builds from the resolved file for {$subject}.");
      $this->assertSame($expectedUrl, $style->toUrlFromEntity($entity), "The entity url builds from the resolved file for {$subject}.");
    }

    $this->assertSame(
      $style->getImageStyle()->buildUrl($this->file->getFileUri()),
      $style->buildUrlForMedia($media),
      'The media url builds from the resolved file.'
    );
  }

  /**
   * Creates an image media item pointing at the shared file.
   */
  private function createImageMedia(): MediaInterface {
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Image media',
      $this->imageSourceField => [
        'target_id' => $this->file->id(),
        'alt' => 'Authored alt',
        'title' => 'Authored title',
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
   * Creates a media item whose thumbnail reference resolves to nothing.
   *
   * The file entity is what goes. The media keeps the reference it was saved
   * with and the reference no longer loads, which is the shape a missing file
   * actually has — and it is the subject the whole contract table is asserted
   * against.
   */
  private function createMediaWithMissingThumbnail(): MediaInterface {
    $doomed = $this->createFileFixture('public://gone.png');
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Media whose file has gone',
      $this->imageSourceField => [
        'target_id' => $doomed->id(),
        'alt' => 'Authored alt',
        'title' => '',
      ],
    ]);
    $media->save();
    $id = $media->id();
    $doomed->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->resetCache([$id]);
    $reloaded = $storage->load($id);
    \assert($reloaded instanceof MediaInterface);
    return $reloaded;
  }

  /**
   * Creates a media item with a valid thumbnail and no source field item.
   *
   * Core hands a media with an empty source field the generic icon; the
   * thumbnail is then pointed at a real file so the subject is exactly "an
   * empty source field beside a valid thumbnail" and nothing else. The second
   * save leaves the thumbnail alone because the source field did not change.
   */
  private function createMediaWithNoSourceItem(): MediaInterface {
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Image media with no source item',
    ]);
    $media->save();
    $media->set('thumbnail', ['target_id' => $this->file->id()]);
    $media->save();
    return $media;
  }

  /**
   * Copies core's test image to the given URI and saves a file entity for it.
   */
  private function createFileFixture(string $uri): FileInterface {
    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      $uri,
      FileExists::Replace
    );
    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

}
