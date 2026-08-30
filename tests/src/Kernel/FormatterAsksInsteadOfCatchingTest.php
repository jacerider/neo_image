<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Component\Render\FormattableMarkup;
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
 * **One record per field render, not one per delta.** The report sits after
 * the loop rather than inside it: a field with two unrenderable references
 * files one warning naming both and saying there were two, and a second field
 * render that skips something files its own. Every value that record
 * interpolates is composed as a string first, because `FormattableMarkup`
 * escapes each one through a non-nullable `string` parameter and a NULL there
 * is a `TypeError` at the moment a site owner opens the report to read the
 * warning.
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
   * It files one record for every reference one field render skips.
   *
   * The volume finding, stated as its own criterion. The log call sat inside
   * the delta loop, so a field with two unrenderable references filed two
   * near-identical records. They arrive together and each describes a
   * condition that is constant for the field rather than for the delta, so
   * what a site owner reads is not two diagnoses; it is one diagnosis
   * repeated until the interesting entries around it are pushed off the page.
   */
  public function testItFilesOneRecordForEveryReferenceOneFieldRenderSkips(): void {
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $this->createMediaWithMissingThumbnail()->id()],
        ['target_id' => $this->createMediaWithMissingThumbnail()->id()],
      ],
    ]);
    $host->save();

    $this->viewElements($host->get(self::MEDIA_FIELD));

    $this->assertCount(
      1,
      $this->neoImageLogRecords(),
      'Two skipped references in one field render leave one record.'
    );
  }

  /**
   * Its record names every reference it skipped and how many there were.
   *
   * Collapsing the volume must not collapse the diagnosis. Every fragment the
   * per-delta message carried survives, and the count is new — it is the thing
   * a reader most wants and previously had to get by counting rows.
   */
  public function testItsRecordNamesEveryReferenceItSkippedAndHowMany(): void {
    $first = $this->createMediaWithMissingThumbnail();
    $second = $this->createMediaWithMissingThumbnail();
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $first->id()],
        ['target_id' => $second->id()],
      ],
    ]);
    $host->save();

    $this->viewElements($host->get(self::MEDIA_FIELD));

    $records = $this->neoImageLogRecords();
    $this->assertCount(1, $records);
    $record = reset($records);
    $message = $this->renderRecord($record);
    $this->assertStringContainsString('media:' . $first->id(), $message, 'The record names the first reference it skipped.');
    $this->assertStringContainsString('media:' . $second->id(), $message, 'The record names the second reference it skipped.');
    $this->assertSame('2', $this->recordVariables($record)['@skipped_count'] ?? NULL, 'The record says how many references it skipped.');
  }

  /**
   * A second field render that skips something files its own record.
   *
   * "Once" is once per `viewElements()` call, not once per request and not
   * once per entity. The state is a local array that dies with the call, so
   * there is no mechanism that could dedupe across calls and silently swallow
   * a second field's diagnosis.
   */
  public function testEachFieldRenderThatSkipsSomethingFilesItsOwnRecord(): void {
    $other = EntityTest::create(['name' => 'Not an image']);
    $other->save();
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $this->createMediaWithMissingThumbnail()->id()],
        ['target_id' => $this->createMediaWithMissingThumbnail()->id()],
      ],
      self::OTHER_FIELD => [['target_id' => $other->id()]],
    ]);
    $host->save();

    $this->viewElements($host->get(self::MEDIA_FIELD));
    $this->viewElements($host->get(self::OTHER_FIELD));

    $records = $this->neoImageLogRecords();
    $this->assertCount(2, $records, 'Each field render that skips something files its own record.');
    $messages = [];
    foreach ($records as $record) {
      $messages[] = $this->renderRecord($record);
    }
    $this->assertStringContainsString(self::MEDIA_FIELD, $messages[0], 'The first record is about the first field.');
    $this->assertStringContainsString(self::OTHER_FIELD, $messages[1], 'The second record is about the second field.');
  }

  /**
   * It files a record the report view can render, label or no label.
   *
   * `FormattableMarkup` hands every placeholder value to `Html::escape()`,
   * whose parameter is a non-nullable `string`, so a NULL there is a
   * `TypeError` at the moment the stored entry is rendered — which is to say
   * when a site owner opens the report to read the warning. The branch that
   * logs is by construction the branch that admits entities of every type,
   * including ones whose label field is empty, so a reference with no label
   * really does reach the message.
   *
   * The assertion is the construction `DbLogController::formatMessage()`
   * makes rather than a proxy for it: `strtr()` swallows the NULL that the
   * report view does not.
   */
  public function testItFilesOneRecordTheReportViewCanRender(): void {
    $nameless = EntityTest::create([]);
    $nameless->save();
    $this->assertNull($nameless->label(), 'The fixture really has no label.');
    $host = EntityTest::create([
      'name' => 'Host',
      self::OTHER_FIELD => [['target_id' => $nameless->id()]],
    ]);
    $host->save();

    $this->viewElements($host->get(self::OTHER_FIELD));

    $records = $this->neoImageLogRecords();
    $this->assertCount(1, $records);
    $message = $this->renderRecord(reset($records));
    $this->assertStringContainsString('entity_test:' . $nameless->id(), $message, 'The record names the reference that has no label.');
  }

  /**
   * Its record is still a warning on the module channel, naming the field.
   *
   * Severity does not move: both conditions the guard can see are faults, and
   * the entity type in each fragment already says which one applied. The field
   * name survives the rewrite too — it is what tells a site builder which
   * display to go and fix.
   */
  public function testItsRecordKeepsItsWarningLevelChannelAndFieldName(): void {
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $this->createMediaWithMissingThumbnail()->id()],
        ['target_id' => $this->createMediaWithMissingThumbnail()->id()],
      ],
    ]);
    $host->save();

    $this->viewElements($host->get(self::MEDIA_FIELD));

    $records = $this->neoImageLogRecords();
    $this->assertCount(1, $records);
    $record = reset($records);
    $this->assertSame('neo_image', $record->type, 'The record is on the module channel.');
    $this->assertSame(RfcLogLevel::WARNING, (int) $record->severity, 'The record is a warning.');
    $this->assertStringContainsString(self::MEDIA_FIELD, $this->renderRecord($record), 'The record names the field.');
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

  /**
   * Renders a stored record the way the dblog report view renders it.
   *
   * `DbLogController::formatMessage()` builds a `FormattableMarkup` from the
   * stored message and its unserialised variables, so that is the construction
   * asserted here. A placeholder value that is NULL raises a `TypeError` at
   * this point and nowhere earlier.
   *
   * @param object $record
   *   The watchdog row.
   *
   * @return string
   *   The message as the report view would show it.
   */
  private function renderRecord(object $record): string {
    return (string) new FormattableMarkup($record->message, $this->recordVariables($record));
  }

  /**
   * Unserialises a stored record's placeholder values.
   *
   * @param object $record
   *   The watchdog row.
   *
   * @return array
   *   The placeholder values, keyed by placeholder.
   */
  private function recordVariables(object $record): array {
    $variables = \unserialize($record->variables, ['allowed_classes' => FALSE]);
    $this->assertIsArray($variables);
    return $variables;
  }

}
