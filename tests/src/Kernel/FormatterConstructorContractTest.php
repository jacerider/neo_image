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
use Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageBaseFormatter;
use Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageMediaFormatter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests that the formatter base's constructor survives an older subclass.
 *
 * The **formatter base** is not final and is the one class in this module a
 * site is expected to extend. Overriding `create()` is the ordinary reason to
 * extend a formatter — a subclass that wants a service of its own writes its
 * own factory — and that factory calls `new static()` with the argument list it
 * was written against. The count is fixed at the subclass's compile time and
 * cannot be corrected from the base, so a required ninth argument is an
 * `ArgumentCountError` on another site the moment a page renders.
 *
 * The ninth argument is therefore an **optional argument**: last, nullable,
 * defaulted to NULL, with the **module channel** resolved by name behind it and
 * a deprecation naming the major it becomes required in. See ADR 0016.
 *
 * **Two construction paths, one channel.** `create()` is untouched and still
 * injects `logger.channel.neo_image`, so the container path never reaches the
 * fallback and never raises a notice; the eight-argument path resolves the same
 * channel by name. `$this->logger` is assigned on both, so nothing downstream
 * guards it.
 *
 * **The eight-argument caller is a fixture, not a mock.** The failure being
 * prevented is `new static()` in someone else's factory, which no mock can
 * reproduce, so `EightArgumentFactoryFormatter` beside this test really is a
 * subclass passing eight arguments.
 *
 * **The absence of a deprecation is captured, not assumed.** This suite runs
 * with `SYMFONY_DEPRECATIONS_HELPER=weak` and no `failOnDeprecation`, so a test
 * that merely constructs through `create()` and asserts nothing would pass
 * whether or not a notice was raised. A scoped error handler records
 * `E_USER_DEPRECATED` instead, and it is shown observing the fallback path's
 * notice before it is trusted to report silence on the container path.
 *
 * **Why kernel and not unit.** The fallback resolves a channel from the
 * container and the records land on a real one, and every subject is a media
 * entity reached through a real field item list.
 */
#[Group('neo_image')]
final class FormatterConstructorContractTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * The deprecation the eight-argument path announces, in full.
   */
  private const DEPRECATION = 'Calling Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageBaseFormatter::__construct() without the $logger argument is deprecated in neo_image:1.1.0 and it will be required in neo_image:2.0.0. See https://github.com/jacerider/neo_image/issues/14';

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

    $this->file = $this->createFileFixture('public://image-test.png');
  }

  /**
   * It constructs from a subclass whose factory passes eight arguments.
   *
   * The whole point: a factory written against the released signature keeps
   * working. Nothing about the object is different afterwards — it is the same
   * class, with a logger, ready to render.
   */
  #[IgnoreDeprecations]
  public function testItConstructsWhenTheSubclassFactoryPassesEightArguments(): void {
    $formatter = $this->eightArgumentFormatter($this->mediaFieldDefinition());

    $this->assertInstanceOf(NeoImageBaseFormatter::class, $formatter);
    $this->assertNotNull(
      $this->formatterLogger($formatter),
      'The property is assigned on the eight-argument path too, so nothing downstream has to guard it.'
    );
  }

  /**
   * It logs to the module channel when constructed without a logger.
   *
   * The fallback resolves the channel by name, which the glossary already
   * states answers the same channel as the injected service. A subclass on the
   * old signature must not also be a subclass with no logs, and it must not
   * file them somewhere a site builder does not look.
   */
  #[IgnoreDeprecations]
  public function testItLogsToTheModuleChannelWhenConstructedWithNoLogger(): void {
    $gone = $this->createMediaWithMissingThumbnail();
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [['target_id' => $gone->id()]],
    ]);
    $host->save();
    $items = $host->get(self::MEDIA_FIELD);

    $this->viewElements(
      $this->eightArgumentFormatter($items->getFieldDefinition()),
      $items
    );

    $records = $this->neoImageLogRecords();
    $this->assertCount(1, $records, 'The fallback logger really does write.');
    $this->assertSame(
      'neo_image',
      reset($records)->type,
      'A formatter constructed without a logger still files on the module channel.'
    );
  }

  /**
   * It names the release the argument becomes required in.
   *
   * "Will be required" is a promise with no runtime voice unless it is
   * announced, so the message is asserted in full rather than by fragment: the
   * versions, the argument it is about, and the `See` url a subclass author
   * follows to find out what to do.
   */
  #[IgnoreDeprecations]
  public function testItTriggersTheDeprecationNamingTheReleaseItBecomesRequiredIn(): void {
    $this->expectUserDeprecationMessage(self::DEPRECATION);

    $this->eightArgumentFormatter($this->mediaFieldDefinition());
  }

  /**
   * The container path uses the injected channel and announces nothing.
   *
   * `create()` is byte-identical, which is what keeps the existing formatter
   * tests a regression pin rather than an edit. Both halves are asserted
   * against something that would fail if they moved: the property is the very
   * object the container holds, not merely a channel of the same name, and the
   * silence is read from a handler shown one line earlier to observe a real
   * notice.
   */
  #[IgnoreDeprecations]
  public function testItUsesTheInjectedChannelAndTriggersNoDeprecationThroughCreate(): void {
    $fieldDefinition = $this->mediaFieldDefinition();

    // The capture is trusted only after it has observed one. Without this the
    // assertion below would pass against a handler that records nothing.
    $observed = $this->captureUserDeprecations(
      fn () => $this->eightArgumentFormatter($fieldDefinition)
    );
    $this->assertSame(
      [self::DEPRECATION],
      $observed,
      'The capture observes the fallback path, so its silence below means something.'
    );

    $formatter = NULL;
    $observed = $this->captureUserDeprecations(function () use ($fieldDefinition, &$formatter): void {
      $formatter = NeoImageMediaFormatter::create(
        $this->container,
        $this->configuration($fieldDefinition),
        'neo_image_media',
        []
      );
    });

    $this->assertSame([], $observed, 'The container path never reaches the fallback.');
    $this->assertSame(
      $this->container->get('logger.channel.neo_image'),
      $this->formatterLogger($formatter),
      'The container path keeps the injected channel.'
    );
  }

  /**
   * It skips and logs a reference that resolves to no file either way.
   *
   * The behaviour under the constructor is one behaviour. A field whose first
   * reference has lost its file renders the second and files one warning
   * naming the first, whether the formatter came from the container or from a
   * factory that predates the logger argument.
   */
  #[IgnoreDeprecations]
  public function testItSkipsAndLogsTheReferenceWithNoFileHoweverItWasConstructed(): void {
    $gone = $this->createMediaWithMissingThumbnail();
    $host = EntityTest::create([
      'name' => 'Host',
      self::MEDIA_FIELD => [
        ['target_id' => $gone->id()],
        ['target_id' => $this->createImageMedia()->id()],
      ],
    ]);
    $host->save();
    $items = $host->get(self::MEDIA_FIELD);
    $fieldDefinition = $items->getFieldDefinition();

    $injected = NeoImageMediaFormatter::create(
      $this->container,
      $this->configuration($fieldDefinition),
      'neo_image_media',
      []
    );
    $this->assertCount(
      1,
      $this->viewElements($injected, $items),
      'The container path skips the reference and renders the rest.'
    );

    $this->assertCount(
      1,
      $this->viewElements($this->eightArgumentFormatter($fieldDefinition), $items),
      'The eight-argument path skips the same reference and renders the rest.'
    );

    $records = $this->neoImageLogRecords();
    $this->assertCount(2, $records, 'Each render files its own record.');
    foreach ($records as $record) {
      $this->assertSame('neo_image', $record->type, 'Both records land on the module channel.');
      $variables = \unserialize($record->variables, ['allowed_classes' => FALSE]);
      $this->assertIsArray($variables);
      $this->assertStringContainsString(
        'media:' . $gone->id(),
        \strtr($record->message, \array_map('strval', $variables)),
        'Both records name the reference that was skipped.'
      );
    }
  }

  /**
   * Builds the formatter through a factory that passes eight arguments.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field the formatter is associated with.
   *
   * @return \Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageBaseFormatter
   *   The formatter, constructed the way a subclass written against the
   *   released signature constructs it.
   */
  private function eightArgumentFormatter(FieldDefinitionInterface $fieldDefinition): NeoImageBaseFormatter {
    return EightArgumentFactoryFormatter::create(
      $this->container,
      $this->configuration($fieldDefinition),
      'neo_image_media',
      []
    );
  }

  /**
   * Records every `E_USER_DEPRECATED` a callback raises.
   *
   * The suite runs with `SYMFONY_DEPRECATIONS_HELPER=weak` and does not set
   * `failOnDeprecation`, so nothing here observes a deprecation unless it is
   * captured deliberately. The handler is scoped to the call and restored
   * before this method returns, because a handler that outlives the test is
   * itself a failure — `checkErrorHandlerOnTearDown()` throws on one.
   *
   * @param callable $callback
   *   The work to run under the handler.
   *
   * @return string[]
   *   The messages raised, in the order they were raised.
   */
  private function captureUserDeprecations(callable $callback): array {
    $observed = [];
    \set_error_handler(
      static function (int $severity, string $message) use (&$observed): bool {
        $observed[] = $message;
        return TRUE;
      },
      E_USER_DEPRECATED
    );
    try {
      $callback();
    }
    finally {
      \restore_error_handler();
    }
    return $observed;
  }

  /**
   * Reads the logger the formatter was constructed with.
   *
   * @param object $formatter
   *   The formatter to read.
   *
   * @return mixed
   *   Whatever the property holds.
   */
  private function formatterLogger(object $formatter): mixed {
    return (new \ReflectionProperty(NeoImageBaseFormatter::class, 'logger'))->getValue($formatter);
  }

  /**
   * The plugin configuration a field formatter factory is handed.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $fieldDefinition
   *   The field the formatter is associated with.
   *
   * @return array
   *   The configuration.
   */
  private function configuration(FieldDefinitionInterface $fieldDefinition): array {
    return [
      'field_definition' => $fieldDefinition,
      'settings' => [
        'image' => [
          'dimensions' => [
            'sm' => [
              'width' => 100,
              'height' => '',
            ],
          ],
        ],
      ],
      'label' => 'hidden',
      'view_mode' => 'default',
      'third_party_settings' => [],
    ];
  }

  /**
   * The media field's definition, for a formatter with nothing to render.
   *
   * @return \Drupal\Core\Field\FieldDefinitionInterface
   *   The definition.
   */
  private function mediaFieldDefinition(): FieldDefinitionInterface {
    $host = EntityTest::create(['name' => 'Host']);
    return $host->get(self::MEDIA_FIELD)->getFieldDefinition();
  }

  /**
   * Runs the formatter over one field, the pair an entity display calls.
   *
   * @param \Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageBaseFormatter $formatter
   *   The formatter to drive.
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The field to view.
   *
   * @return array
   *   The formatter's elements, keyed by the delta each came from.
   */
  private function viewElements(NeoImageBaseFormatter $formatter, FieldItemListInterface $items): array {
    $formatter->prepareView([$items->getEntity()->id() => $items]);
    return $formatter->viewElements($items, 'en');
  }

  /**
   * Creates an unlimited entity reference field on the test host entity.
   *
   * @param string $fieldName
   *   The field to create.
   * @param string $targetType
   *   The entity type it references.
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
   *   Where to put it.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file.
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
