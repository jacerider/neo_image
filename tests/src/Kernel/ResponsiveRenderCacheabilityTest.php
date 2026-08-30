<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImage;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\NeoImageUtility;
use Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageMediaFormatter;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the responsive render declares the subject cacheability too.
 *
 * The **responsive render** is `NeoImage` — `#theme => neo_image`. Built from
 * an **entity-sourced image** it reads the same two entities the
 * **single-style render** reads: the subject, for its **authored alt** and
 * title, and the **resolved file**, for the URI. It has to declare the same
 * **subject cacheability**, or swapping a component from one shape to the
 * other would change what invalidates it.
 *
 * **The carrying is the mechanism under test.** The factory is the only place
 * the subject is in scope and the render array is built somewhere else
 * entirely, so `NeoImage` carries a `CacheableMetadata` between them. It holds
 * the metadata and not the entity: an entity kept on a render object invites a
 * second resolution later and gives the object a lifetime it should not have.
 *
 * **Two of these criteria are boundaries rather than additions.** A `NeoImage`
 * built from a URI string still declares nothing, because a string names no
 * entity, and the factory still throws for a subject that resolves to no file,
 * because a factory must answer an object and has none to answer. Both pass
 * against the state a revert restores; they are here because the tempting
 * implementations of the other three cross them.
 *
 * **Why kernel and not unit.** Every assertion is about what a real media and
 * the real file behind it put into a render array, and the last one drives a
 * field formatter over a real field item list. A unit test would have to mock
 * `MediaInterface`, its source plugin and the file's cacheability, and would
 * then be asserting the mocks.
 */
#[Group('neo_image')]
final class ResponsiveRenderCacheabilityTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * The field referencing media, which the formatter is asked to render.
   */
  private const MEDIA_FIELD = 'field_neo_images';

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
    'entity_test',
    'neo_settings',
    // `neo-image.html.twig` uses `neo_attributes`, a filter `neo_twig`
    // registers. Twig parses the whole template whichever branch renders.
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
    $this->installEntitySchema('entity_test');
    $this->installConfig(['field', 'system', 'user', 'image', 'file', 'media']);

    // Access is not what any of this asserts, and an anonymous kernel user may
    // view neither a media item nor a test entity.
    $this->setUpCurrentUser([], [], TRUE);

    $imageType = $this->createMediaType('image', ['id' => 'image']);
    $this->imageSourceField = $imageType->getSource()->getConfiguration()['source_field'];

    $this->createReferenceField(self::MEDIA_FIELD, 'media');

    $this->file = $this->createFileFixture('public://image-test.png');
  }

  /**
   * It declares the subject's tag and its resolved file's tag.
   *
   * The render reads two entities and now says so in the array it answers. An
   * editor saving the media and a file replaced behind it both invalidate
   * whatever render cache this bubbled into; the file's tag is the half a
   * caller declaring the subject by hand has never had.
   */
  public function testTheResponsiveRenderDeclaresTheSubjectAndItsResolvedFile(): void {
    $media = $this->createImageMedia();
    $resolved = NeoImageUtility::resolvedFile($media);
    $this->assertInstanceOf(FileInterface::class, $resolved);

    $build = NeoImage::createFromEntity($media)->toRenderable();

    $this->assertSame(
      $this->sorted(array_merge($media->getCacheTags(), $resolved->getCacheTags())),
      $this->sorted($build['#cache']['tags'] ?? []),
      'The responsive render declares the subject and its resolved file.'
    );
  }

  /**
   * It declares the same tags the single-style render declares.
   *
   * Asserted as an equality between the two renders rather than as two
   * independent literals, because the equality is the point: the two shapes
   * are alternatives for the same subject, and a site builder swapping one for
   * the other should change the markup and nothing else. Two literals would
   * still both be true after the two layers had drifted apart.
   */
  public function testBothRendersDeclareTheSameTagsForTheSameSubject(): void {
    $media = $this->createImageMedia();

    $responsive = NeoImage::createFromEntity($media)->toRenderable();
    $single = (new NeoImageStyle([]))->toRenderableFromEntity($media);

    $this->assertNotEmpty(
      $single['#cache']['tags'] ?? [],
      'The single-style render declares tags, so the equality is not vacuous.'
    );
    $this->assertSame(
      $this->sorted($single['#cache']['tags'] ?? []),
      $this->sorted($responsive['#cache']['tags'] ?? []),
      'The two renders declare the same tags for the same subject.'
    );
  }

  /**
   * It declares no tags for a render built from a uri string.
   *
   * A URI names no entity, so there is nothing to invalidate on and nothing to
   * declare. Deriving a file entity from a path would be a new lookup and a
   * new failure mode, not a declaration of something the render read. The
   * metadata carried is simply empty, so the render applies it rather than
   * branching on whether it has one.
   */
  public function testUriStringSubjectDeclaresNoTags(): void {
    $build = (new NeoImage('public://image-test.png'))->toRenderable();

    $this->assertSame(
      [],
      $build['#cache']['tags'] ?? [],
      'A render built from a uri string declares no tags.'
    );
  }

  /**
   * It still throws for a subject that resolves to no file.
   *
   * The factory's **failure contract** is a throw, and it is a throw because a
   * factory must answer an object and has none to answer. The derivation never
   * refuses, so it would happily answer for this subject; carrying it must not
   * turn "there is nothing to render" into an object with metadata attached
   * and no image behind it.
   */
  public function testTheFactoryStillThrowsWhenNoFileResolves(): void {
    $gone = $this->createMediaWithMissingThumbnail();
    $this->assertNull(NeoImageUtility::resolvedFile($gone));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The entity does not resolve to a file.');
    NeoImage::createFromEntity($gone);
  }

  /**
   * It gives the formatter's element the resolved file's tag.
   *
   * The formatter keeps its own `addCacheableDependency()` line, which is now
   * a merge of something the render already declared. The discriminating
   * evidence that the render is doing the work is the file's tag: that line
   * declares the subject and has never declared the file, so a file replaced
   * behind an unsaved media left the element stale.
   */
  public function testTheFormatterElementCarriesTheResolvedFileTag(): void {
    $media = $this->createImageMedia();
    $resolved = NeoImageUtility::resolvedFile($media);
    $this->assertInstanceOf(FileInterface::class, $resolved);
    $fileTag = $resolved->getCacheTags()[0];
    $this->assertNotContains(
      $fileTag,
      $media->getCacheTags(),
      'The subject alone never carried the file tag, so it discriminates.'
    );

    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [['target_id' => $media->id()]],
    ]);
    $host->save();

    $elements = $this->viewElements($host->get(self::MEDIA_FIELD));

    $this->assertArrayHasKey(0, $elements);
    $tags = $elements[0]['#cache']['tags'] ?? [];
    $this->assertContains($fileTag, $tags, 'The element carries the file tag.');
    $this->assertContains('media:' . $media->id(), $tags, 'And the subject.');
  }

  /**
   * Runs the formatter over one field, the pair an entity display calls.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field to view.
   *
   * @return array
   *   The formatter's elements, keyed by the delta each came from.
   */
  private function viewElements(FieldItemListInterface $items): array {
    $formatter = $this->formatter($items->getFieldDefinition());
    $formatter->prepareView([$items->getEntity()->id() => $items]);
    return $formatter->viewElements($items, 'en');
  }

  /**
   * Builds the formatter under test.
   *
   * It is driven directly rather than through an entity display: its traits
   * come from `neo`, whose whole dependency chain would otherwise have to be
   * installed before plugin discovery would so much as list it, and none of
   * that chain is what this asserts.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The definition of the field the formatter renders.
   *
   * @return \Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageMediaFormatter
   *   The formatter.
   */
  private function formatter(FieldDefinitionInterface $fieldDefinition): NeoImageMediaFormatter {
    return new NeoImageMediaFormatter(
      'neo_image_media',
      [],
      $fieldDefinition,
      [
        'image' => [
          'dimensions' => [
            'sm' => [
              'width' => 100,
              'height' => '',
            ],
          ],
        ],
      ],
      'hidden',
      'default',
      [],
      $this->container->get('renderer'),
      $this->container->get('logger.factory')->get('neo_image')
    );
  }

  /**
   * Creates an unlimited entity reference field on the test host entity.
   *
   * @param string $fieldName
   *   The field name to create.
   * @param string $targetType
   *   The entity type the field references.
   */
  private function createReferenceField(string $fieldName, string $targetType): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'entity_test',
      'type' => 'entity_reference',
      'settings' => ['target_type' => $targetType],
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
  }

  /**
   * Creates an image media item pointing at the shared file.
   *
   * @return \Drupal\media\MediaInterface
   *   The media item.
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
   *
   * @return \Drupal\media\MediaInterface
   *   The media item.
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
   *
   * @param string $uri
   *   The URI to copy the fixture to.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file entity.
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
   *
   * @param array $tags
   *   The tags to sort.
   *
   * @return array
   *   The sorted tags.
   */
  private function sorted(array $tags): array {
    sort($tags);
    return $tags;
  }

}
