<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\File\FileExists;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\NeoImageUtility;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the subject cacheability and the single-style render that declares it.
 *
 * The **subject cacheability** is the cache metadata an **entity-sourced
 * image** render depends on: the subject entity's own, merged with its
 * **resolved file**'s. Both halves are read per render — the authored alt and
 * title from the subject, the URI from the file — and they go stale
 * independently, which is why declaring only the subject is what a
 * hand-written version gets wrong.
 *
 * **The failure contract is the boundary.** A subject that resolves to no file
 * still answers exactly `[]` from the single-style render. The derivation
 * never refuses, so it could be applied before the resolved-file guard; doing
 * so would put one key on the empty answer, and an array with one key is
 * truthy. `ResolvedFileContractTest` pins that answer too, and this class
 * restates it as the line this change is not allowed to cross.
 *
 * **Why kernel and not unit.** Every assertion is about what a real media and
 * the real file behind it put into a render array. A unit test would have to
 * mock `MediaInterface`, its source plugin and the file's cacheability, and
 * would then be asserting the mocks.
 */
#[Group('neo_image')]
final class SubjectCacheabilityTest extends KernelTestBase {

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

    $this->file = $this->createFileFixture('public://image-test.png');
  }

  /**
   * It answers a media's own tag and its resolved file's tag.
   *
   * Both halves, because both are read: the alt and title come from the media
   * and the URI comes from the file. A file edited without its media being
   * saved is the case the subject's tag alone does not cover.
   */
  public function testItAnswersTheMediaAndItsResolvedFile(): void {
    $media = $this->createImageMedia();
    $resolved = NeoImageUtility::resolvedFile($media);
    $this->assertInstanceOf(FileInterface::class, $resolved);

    $cacheability = NeoImageUtility::subjectCacheability($media);

    $this->assertInstanceOf(CacheableMetadata::class, $cacheability);
    $this->assertSame(
      $this->sorted(array_merge($media->getCacheTags(), $resolved->getCacheTags())),
      $this->sorted($cacheability->getCacheTags()),
      'The media subject declares its own tag and its resolved file\'s.'
    );
  }

  /**
   * It answers a bare file subject's own tag and invents nothing beside it.
   *
   * A file resolves to itself, so the merge is the same file twice and the
   * answer is one tag. Anything else in the set would be this module inventing
   * a dependency rather than declaring one it read.
   */
  public function testBareFileSubjectAnswersItsOwnTagAlone(): void {
    $cacheability = NeoImageUtility::subjectCacheability($this->file);

    $this->assertSame(
      $this->sorted($this->file->getCacheTags()),
      $this->sorted($cacheability->getCacheTags()),
      'A file subject declares its own tag and nothing else.'
    );
  }

  /**
   * It answers the subject's tag alone when the subject resolves to no file.
   *
   * The derivation never refuses, matching `resolvedFile()`'s rule, so a
   * caller may derive it before knowing whether a render will happen. The
   * absent file contributes nothing rather than making the answer nothing.
   */
  public function testItAnswersTheSubjectAloneWhenThereIsNoFile(): void {
    $gone = $this->createMediaWithMissingThumbnail();
    $this->assertNull(NeoImageUtility::resolvedFile($gone), 'The subject is one the resolver answers nothing for.');

    $cacheability = NeoImageUtility::subjectCacheability($gone);

    $this->assertSame(
      $this->sorted($gone->getCacheTags()),
      $this->sorted($cacheability->getCacheTags()),
      'A subject with no file declares its own tag and no other.'
    );
  }

  /**
   * It declares both tags from the single-style render for a media subject.
   *
   * The render reads two entities and now says so in the array it answers. An
   * editor saving the media and a file replaced behind it both invalidate
   * whatever render cache this bubbled into.
   */
  public function testTheSingleStyleRenderDeclaresBothTags(): void {
    $media = $this->createImageMedia();
    $resolved = NeoImageUtility::resolvedFile($media);
    $this->assertInstanceOf(FileInterface::class, $resolved);

    $build = (new NeoImageStyle([]))->toRenderableFromEntity($media);

    $this->assertSame(
      $this->sorted(array_merge($media->getCacheTags(), $resolved->getCacheTags())),
      $this->sorted($build['#cache']['tags'] ?? []),
      'The render declares the subject and its resolved file.'
    );
  }

  /**
   * It leaves the empty failure answer exactly empty.
   *
   * The **failure contract** of the render **entry point** is exactly `[]`,
   * and callers branch on its truthiness. The derivation never refuses, so it
   * would happily answer for a subject with no file; applying it before the
   * resolved-file guard is how a caching change silently becomes a behaviour
   * change, because an array carrying one key is truthy.
   */
  public function testTheEmptyFailureAnswerStaysExactlyEmpty(): void {
    $gone = $this->createMediaWithMissingThumbnail();

    $this->assertSame([], (new NeoImageStyle([]))->toRenderableFromEntity($gone));
  }

  /**
   * It declares tags and no cache keys, so it creates no cache entry.
   *
   * `#cache` without `#cache[keys]` is inert: the renderer creates no entry of
   * its own for this element and the tags bubble to whatever cache sits above
   * it. That is what makes declaring them free per render, and it is the
   * property a `keys` added later would quietly cost.
   */
  public function testTheRenderDeclaresNoCacheKeys(): void {
    $media = $this->createImageMedia();

    $build = (new NeoImageStyle([]))->toRenderableFromEntity($media);

    $this->assertNotEmpty($build['#cache']['tags'] ?? [], 'The render declares tags.');
    $this->assertArrayNotHasKey('keys', $build['#cache'], 'The render creates no cache entry of its own.');
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
   * Creates a media item whose thumbnail reference resolves to nothing.
   *
   * The file entity is what goes: the media keeps the reference it was saved
   * with and the reference no longer loads, which is the shape a missing file
   * actually has.
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

  /**
   * Sorts a tag list, so a set comparison does not assert an order.
   */
  private function sorted(array $tags): array {
    sort($tags);
    return $tags;
  }

}
