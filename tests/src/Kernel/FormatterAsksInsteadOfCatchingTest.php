<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Render\RendererInterface;
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
use Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageMediaFormatter;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the field formatter asks for the file instead of catching.
 *
 * The formatter wrapped its whole render loop in an empty
 * `catch (\Exception $e) {}`, written because the caller could not predict
 * which of the four **entry points** it was calling into would throw. What
 * that cost was the trace: a media whose file has gone missing rendered
 * nothing, logged nothing, and left a site owner with a blank space and no way
 * to find out why.
 *
 * With the **resolved file** step in place the formatter asks first. A
 * reference that resolves to no file is skipped and **logged**, and the rest of
 * the field keeps rendering. The guard is also the type narrowing — the loop
 * iterates entities typed as plain entities — so anything that is neither a
 * media nor a file is skipped the same way rather than reaching a factory whose
 * parameter it does not satisfy. A `\TypeError` is an `\Error` rather than an
 * `\Exception`, so that reference was never caught by the blanket catch at all
 * and took the whole field render down with it.
 *
 * The blanket catch goes rather than learning to log: once the one predictable
 * failure is guarded, everything else it caught is a bug, and a bug that
 * renders nothing and logs nothing is the exact friction this removes.
 *
 * **The formatter is driven directly rather than through a display.** Its
 * traits come from `neo`, whose whole dependency chain — `neo_build`,
 * `neo_color`, `neo_icon`, `linkit` — would otherwise have to be installed
 * before plugin discovery would so much as list the formatter. None of that
 * chain is what any criterion here is about, and `prepareView()` followed by
 * `viewElements()` is the pair an entity display calls anyway.
 *
 * **The log is read from `dblog`.** The criterion is a warning naming the
 * entity that was skipped; the mechanism is the implementer's choice, and the
 * watchdog table is the one a site owner actually reads.
 *
 * **Why kernel and not unit.** Every subject is a media entity resolved through
 * its source plugin and its thumbnail reference, reached through a real field
 * item list. A unit test could only assert mocks of all three.
 */
#[Group('neo_image')]
final class FormatterAsksInsteadOfCatchingTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * The field referencing media, which the formatter is asked to render.
   */
  private const MEDIA_FIELD = 'field_neo_images';

  /**
   * A field referencing entities that are neither a media nor a file.
   */
  private const OTHER_FIELD = 'field_neo_others';

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
    'dblog',
    'entity_test',
    'neo_settings',
    // `neo-image.html.twig` uses `neo_attributes`, a filter `neo_twig`
    // registers.
    'neo_twig',
    'neo_image',
  ];

  /**
   * The file the resolvable fixtures point at.
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
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['field', 'system', 'user', 'image', 'file', 'media']);

    // Access is not what any of this asserts, and an anonymous kernel user may
    // view neither a media item nor a test entity.
    $this->setUpCurrentUser([], [], TRUE);

    $imageType = $this->createMediaType('image', ['id' => 'image']);
    $this->imageSourceField = $imageType->getSource()->getConfiguration()['source_field'];

    $this->createReferenceField(self::MEDIA_FIELD, 'media');
    $this->createReferenceField(self::OTHER_FIELD, 'entity_test');

    $this->file = $this->createFileFixture('public://image-test.png');
  }

  /**
   * It renders the remaining items when one reference resolves to no file.
   *
   * Two shapes of "no file", because the formatter used to treat them as two
   * entirely different events. A media whose file has gone reached the factory
   * and threw an `\InvalidArgumentException` the blanket catch swallowed, so
   * the rest of the field survived — silently. An entity that is neither a
   * media nor a file never satisfied the factory's parameter at all and raised
   * a `\TypeError`, which is an `\Error` and not an `\Exception`, so it went
   * straight past that catch and took the whole field render down with it.
   *
   * Both are skipped the same way now, and the field keeps rendering either
   * way.
   */
  public function testItRendersTheRemainingItemsWhenOneReferenceHasNoFile(): void {
    $other = EntityTest::create(['name' => 'Not an image']);
    $other->save();
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $this->createMediaWithMissingThumbnail()->id()],
        ['target_id' => $this->createImageMedia()->id()],
      ],
      self::OTHER_FIELD => [['target_id' => $other->id()]],
    ]);
    $host->save();

    $elements = $this->viewElements($host->get(self::MEDIA_FIELD));
    $this->assertCount(1, $elements, 'The reference that resolves is still rendered.');
    $markup = $this->renderElements($elements);
    $this->assertSame(1, substr_count($markup, '<img'));
    $this->assertStringContainsString('image-test', $markup, 'The image rendered is the one that resolves.');

    // A reference that is neither a media nor a file is skipped the same way,
    // instead of raising the `\TypeError` no catch here ever caught.
    $this->assertSame([], $this->viewElements($host->get(self::OTHER_FIELD)));
  }

  /**
   * It logs a warning naming the entity it skipped.
   *
   * A warning that says only "an image was missing" is the same silence with
   * extra steps, so the record carries the entity's id — something a site owner
   * can look up — alongside its label.
   */
  public function testItWarnsNamingTheEntityItSkipped(): void {
    $gone = $this->createMediaWithMissingThumbnail();
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $gone->id()],
        ['target_id' => $this->createImageMedia()->id()],
      ],
    ]);
    $host->save();

    $this->viewElements($host->get(self::MEDIA_FIELD));

    $records = $this->neoImageLogRecords();
    $this->assertCount(1, $records, 'One skipped reference leaves one record.');

    $record = reset($records);
    $this->assertSame(RfcLogLevel::WARNING, (int) $record->severity, 'The record is a warning.');

    $variables = \unserialize($record->variables, ['allowed_classes' => FALSE]);
    $this->assertIsArray($variables);
    $message = \strtr($record->message, \array_map('strval', $variables));
    $this->assertStringContainsString((string) $gone->id(), $message, 'The record names the id it skipped.');
    $this->assertStringContainsString((string) $gone->label(), $message, 'The record names the entity it skipped.');
  }

  /**
   * It lets an exception from anywhere else in the render loop surface.
   *
   * The blanket catch is removed rather than made to log. Everything it still
   * caught once the predictable failure was guarded is a bug, and a bug that
   * renders nothing and logs nothing is what this ticket exists to end. The
   * failure is injected at the renderer, the one collaborator the loop reaches
   * after the image is built and the only one it does not already guard.
   */
  public function testItLetsAnExceptionFromElsewhereInTheLoopSurface(): void {
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [['target_id' => $this->createImageMedia()->id()]],
    ]);
    $host->save();

    $renderer = $this->createMock(RendererInterface::class);
    $renderer->method('addCacheableDependency')
      ->willThrowException(new \RuntimeException('A bug in the render loop.'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('A bug in the render loop.');
    $this->viewElements($host->get(self::MEDIA_FIELD), $renderer);
  }

  /**
   * It renders an entity-sourced image unchanged when every reference resolves.
   *
   * Nothing about the formatter's settings, its dimensions handling, its link
   * building or its cacheability changes, so the happy path is pinned rather
   * than discovered: every delta renders, each from its own resolved file,
   * carrying its authored alt and the cacheability of the entity it came from.
   */
  public function testItRendersAnEntitySourcedImageUnchangedWhenEveryReferenceResolves(): void {
    $second = $this->createFileFixture('public://second-image.png');
    $first = $this->createImageMedia();
    $other = $this->createImageMedia('Second alt', $second);
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $first->id()],
        ['target_id' => $other->id()],
      ],
    ]);
    $host->save();

    $elements = $this->viewElements($host->get(self::MEDIA_FIELD));
    $this->assertSame([0, 1], array_keys($elements), 'Every resolvable reference keeps its delta.');
    foreach ([0 => $first, 1 => $other] as $delta => $media) {
      $this->assertArrayHasKey('#neoImage', $elements[$delta], "Delta {$delta} builds a responsive image.");
      $this->assertContains(
        'media:' . $media->id(),
        $elements[$delta]['#cache']['tags'] ?? [],
        "Delta {$delta} keeps the cacheability of the entity it came from."
      );
    }

    $markup = $this->renderElements($elements);
    $this->assertSame(2, substr_count($markup, '<img'));
    $this->assertStringContainsString('image-test', $markup);
    $this->assertStringContainsString('second-image', $markup);
    $this->assertStringContainsString('alt="Authored alt"', $markup);
    $this->assertStringContainsString('alt="Second alt"', $markup);
    $this->assertStringContainsString('width="100"', $markup, 'The configured dimensions still apply.');
    $this->assertCount(0, $this->neoImageLogRecords(), 'Nothing is skipped, so nothing is logged.');
  }

  /**
   * Runs the formatter over one field, the pair an entity display calls.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field to view.
   * @param \Drupal\Core\Render\RendererInterface|null $renderer
   *   (optional) A renderer to hand the formatter in place of the real one.
   *
   * @return array
   *   The formatter's elements, keyed by the delta each came from.
   */
  private function viewElements(FieldItemListInterface $items, ?RendererInterface $renderer = NULL): array {
    $formatter = $this->formatter($items->getFieldDefinition(), $renderer);
    $formatter->prepareView([$items->getEntity()->id() => $items]);
    return $formatter->viewElements($items, 'en');
  }

  /**
   * Builds the formatter under test.
   */
  private function formatter(FieldDefinitionInterface $fieldDefinition, ?RendererInterface $renderer = NULL): NeoImageMediaFormatter {
    return new NeoImageMediaFormatter(
      'neo_image_media',
      [],
      $fieldDefinition,
      $this->imageSettings(),
      'hidden',
      'default',
      [],
      $renderer ?? $this->container->get('renderer'),
      $this->container->get('logger.factory')->get('neo_image')
    );
  }

  /**
   * Renders the formatter's elements and returns their markup.
   */
  private function renderElements(array $elements): string {
    return (string) $this->container->get('renderer')->renderRoot($elements);
  }

  /**
   * The formatter's image settings: one breakpoint, one declared width.
   *
   * @return array
   *   The formatter settings.
   */
  private function imageSettings(): array {
    return [
      'image' => [
        'dimensions' => [
          'sm' => [
            'width' => 100,
            'height' => '',
          ],
        ],
      ],
    ];
  }

  /**
   * Creates an unlimited entity reference field on the test host entity.
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
   * Creates an image media item pointing at a resolvable file.
   */
  private function createImageMedia(string $alt = 'Authored alt', ?FileInterface $file = NULL): MediaInterface {
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Image media',
      $this->imageSourceField => [
        'target_id' => ($file ?? $this->file)->id(),
        'alt' => $alt,
        'title' => '',
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
   * Reads every watchdog record written on the module's own channel.
   *
   * @return object[]
   *   The records, oldest first.
   */
  private function neoImageLogRecords(): array {
    return $this->container->get('database')->select('watchdog', 'w')
      ->fields('w', ['wid', 'type', 'message', 'variables', 'severity'])
      ->condition('w.type', 'neo_image')
      ->orderBy('w.wid')
      ->execute()
      ->fetchAll();
  }

}
