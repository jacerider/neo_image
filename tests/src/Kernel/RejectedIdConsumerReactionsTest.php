<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\Settings\ImageSettings;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what each consumer does with a **rejected id**.
 *
 * Ticket 02 gave the **codec** an **id grammar** and a refusal: a name outside
 * it throws `\InvalidArgumentException` out of `convertIdToParams()`. Its three
 * consumers still called it unguarded, so the refusal reached a route as a
 * fatal, a filesystem scan as a fatal and an admin form as a fatal. This class
 * pins the three reactions, each in that consumer's own terms.
 *
 * The **param converter answers nothing**, and stays silent while doing it: the
 * name comes out of a URL an attacker controls, so a log line there is a flood
 * vector and the 404 in the access log is already the record.
 *
 * The **style manager skips and logs**. A **derivative directory** it cannot
 * parse is site state somebody should know about rather than a request, so it
 * is skipped instead of being promoted into a style, an admin-form option and a
 * flush target — and named once on the module's own channel.
 *
 * **Skipping does not orphan what it skips.** The admin flush iterates the
 * directory *names* on disk rather than the styles the manager parsed, so junk
 * a site already carries is still removable. That is the cleanup path; the
 * converter's refusal is what stops more appearing.
 *
 * **The settings form stops calling the codec.** It re-parsed the id its own
 * select had just handed it, which was both a third call site and a place a
 * tampered submission could throw inside an admin form. It takes the style the
 * manager already parsed instead, and reports a value the manager does not know
 * as a form error.
 *
 * **Why kernel and not unit.** None of the three subjects is pure: the
 * converter loads a config entity before it reaches the codec, the manager
 * walks real stream wrappers, and the settings plugin is built by a form.
 * Tickets 01 and 02 are unit tests because their subject is arithmetic and
 * string handling; this one is not.
 *
 * **The log is read from `dblog`,** matching the module's other kernel test:
 * the criterion is a warning naming the directory, and the watchdog table is
 * the one a site owner actually reads.
 */
#[Group('neo_image')]
final class RejectedIdConsumerReactionsTest extends KernelTestBase {

  /**
   * An id the grammar refuses: `z` is not a property key.
   */
  private const REJECTED_ID = 'neo-s--z-1';

  /**
   * A second refused id: no value for the width it names.
   */
  private const REJECTED_ID_NO_VALUE = 'neo-s--w';

  /**
   * A well-formed id: a scale effect at width 300.
   */
  private const ACCEPTED_ID = 'neo-s--w-300';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'dblog',
    'neo_settings',
    'neo_image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('dblog', ['watchdog']);
    $this->installConfig(['system', 'image', 'file', 'neo_image']);
  }

  /**
   * The param converter answers nothing, and the image route 404s.
   *
   * Acceptance criterion: it answers nothing from the param converter for a
   * rejected name, and 404s the image route.
   *
   * NULL is exactly what the converter already answers for an unknown style
   * name that does not start `neo-`, and the second half of the criterion is
   * why that matters: a converter answering NULL for a parameter the route has
   * no default for is how Drupal produces a 404. That mechanism is asserted
   * against the real `image.style_public` route — the route this module's own
   * subscriber bolts the `image_style_dynamic` converter onto — rather than a
   * hand-built stand-in, so the pin covers the wiring as well as the reaction.
   *
   * Silence is asserted alongside it. The name arrives from a URL an attacker
   * controls; logging there would let anyone fill the log by asking.
   */
  public function testItAnswersNothingFromTheParamConverterAndTheImageRoute404s(): void {
    $converter = $this->container->get('neo_image.param_converter');
    $definition = ['type' => 'image_style_dynamic'];

    foreach ([self::REJECTED_ID, self::REJECTED_ID_NO_VALUE, 'neo-x--w-1', 'neo-'] as $rejected) {
      $this->assertNull(
        $converter->convert($rejected, $definition, 'image_style', []),
        sprintf('The converter answers nothing for "%s".', $rejected)
      );
    }

    $this->assertCount(
      0,
      $this->neoImageLogRecords(),
      'The converter says nothing about a name it refuses: the url is attacker-controllable.'
    );

    // And nothing is what Drupal turns into a 404, on the real image route.
    $routeProvider = $this->container->get('router.route_provider');
    $route = $routeProvider->getRouteByName('image.style_public');
    $this->assertSame(
      [
        'type' => 'image_style_dynamic',
        'converter' => 'neo_image.param_converter',
      ],
      $route->getOption('parameters')['image_style'],
      'The module has put its own converter on the public image style route.'
    );

    $this->expectException(ParamNotConvertedException::class);
    $this->container->get('paramconverter_manager')->convert([
      RouteObjectInterface::ROUTE_OBJECT => $route,
      RouteObjectInterface::ROUTE_NAME => 'image.style_public',
      'image_style' => self::REJECTED_ID,
      'scheme' => 'public',
    ]);
  }

  /**
   * A configured style still upcasts, and a well-formed neo name still builds.
   *
   * Acceptance criterion: it still upcasts a configured image style, and still
   * builds a style from a well-formed neo name.
   *
   * The blast radius of the refusal, stated. This is a regression pin rather
   * than a driver: it is green before the change as well as after, because
   * nothing well-formed is allowed to move. The three answers the converter can
   * give — a config entity, a built style, nothing at all — are pinned
   * together, since the change adds a fourth path into the third of them.
   */
  public function testItStillUpcastsConfiguredStylesAndBuildsWellFormedNeoNames(): void {
    $converter = $this->container->get('neo_image.param_converter');
    $definition = ['type' => 'image_style_dynamic'];

    $configured = ImageStyle::create(['name' => 'neo_image_test', 'label' => 'Test']);
    $configured->save();
    $upcast = $converter->convert('neo_image_test', $definition, 'image_style', []);
    $this->assertInstanceOf(ImageStyleInterface::class, $upcast);
    $this->assertSame('neo_image_test', $upcast->id(), 'A configured image style is still loaded.');

    $built = $converter->convert(self::ACCEPTED_ID, $definition, 'image_style', []);
    $this->assertInstanceOf(ImageStyleInterface::class, $built);
    $this->assertSame(
      self::ACCEPTED_ID,
      $built->getName(),
      'A well-formed neo name still builds a style, named the same as the id that asked for it.'
    );
    $this->assertNotEmpty($built->getEffects()->getConfiguration(), 'And that style still carries its effects.');

    $this->assertNull(
      $converter->convert('no_such_style', $definition, 'image_style', []),
      'An unknown name that is not a neo name still answers nothing.'
    );
  }

  /**
   * A directory the grammar refuses is skipped, and named once in the log.
   *
   * Acceptance criterion: it skips a styles directory it cannot parse, and logs
   * it once.
   *
   * Before this ticket the manager handed every `neo-` directory straight to
   * the codec, so one piece of junk on disk took down every caller of
   * `getStyles()` — the settings form, the flush hook and the field formatter's
   * settings among them. It is skipped instead.
   *
   * Once, not once per call: `getStyles()` memoises its scan for the request,
   * so the second call must add nothing. That memoisation is deliberately not
   * touched by this ticket, and this assertion is what says so out loud.
   */
  public function testItSkipsAnUnparseableStylesDirectoryAndLogsItOnce(): void {
    $this->makeStyleDirectory(self::ACCEPTED_ID);
    $this->makeStyleDirectory(self::REJECTED_ID);

    $manager = $this->container->get('neo_image.style_manager');
    $styles = $manager->getStyles();

    $this->assertSame(
      [self::ACCEPTED_ID],
      array_keys($styles),
      'Only the directory the grammar admits becomes a style.'
    );
    $this->assertInstanceOf(NeoImageStyle::class, $styles[self::ACCEPTED_ID]);
    $this->assertSame(['s' => ['w' => 300]], $styles[self::ACCEPTED_ID]->getParameters());

    // The scan is memoised, so a second caller re-reads nothing and re-logs
    // nothing.
    $manager->getStyles();

    $records = $this->neoImageLogRecords();
    $this->assertCount(1, $records, 'One unparseable directory leaves one record.');
    $record = reset($records);
    $this->assertSame(RfcLogLevel::WARNING, (int) $record->severity, 'The record is a warning.');

    $variables = \unserialize($record->variables, ['allowed_classes' => FALSE]);
    $this->assertIsArray($variables);
    $message = \strtr($record->message, \array_map('strval', $variables));
    $this->assertStringContainsString(
      self::REJECTED_ID,
      $message,
      'The record names the directory it skipped.'
    );
  }

  /**
   * The flush removes every neo directory, parseable or not.
   *
   * Acceptance criterion: it still deletes every neo directory when the styles
   * are flushed, parseable or not.
   *
   * This is the other half of skipping. The admin flush used to iterate the
   * styles the manager had parsed, so the moment the manager stopped parsing a
   * junk directory that directory became undeletable through the UI — the
   * change would have closed the door and locked the existing junk inside. The
   * flush iterates directory *names* from disk instead.
   *
   * A non-`neo-` directory is created alongside them to pin the other edge: the
   * flush owns the directories this module writes and nothing else, so a
   * configured style's derivatives are none of its business.
   */
  public function testItStillDeletesEveryNeoDirectoryWhenTheStylesAreFlushed(): void {
    $accepted = $this->makeStyleDirectory(self::ACCEPTED_ID);
    $rejected = $this->makeStyleDirectory(self::REJECTED_ID);
    $foreign = $this->makeStyleDirectory('large');

    $form = [];
    $formState = new FormState();
    $this->imageSettings()->flushImageStyles($form, $formState);

    $this->assertDirectoryDoesNotExist($accepted, 'A parseable neo directory is flushed.');
    $this->assertDirectoryDoesNotExist($rejected, 'So is one the grammar refuses.');
    $this->assertDirectoryExists($foreign, 'A configured style\'s directory is left alone.');
  }

  /**
   * A style the manager does not know is a form error, not an exception.
   *
   * Acceptance criterion: it reports an unknown style on the settings form as a
   * form error rather than an exception.
   *
   * The select's options come from the manager, so a value it does not know
   * arrived by tampering. Before this ticket the validator answered that by
   * re-parsing the value through the codec, which after ticket 02 throws —
   * turning a crafted POST into a fatal inside an admin form. It asks the
   * manager instead, which is also how the third call site into the **codec**
   * disappears: the grammar is left with two consumers.
   *
   * The accepted half is asserted in the same method because it is the same
   * branch: a known style still fills width, height, exactness and background
   * from the style the manager parsed, and the id itself is still unset before
   * the values are saved.
   */
  public function testItReportsAnUnknownStyleOnTheSettingsFormAsFormError(): void {
    $this->makeStyleDirectory(self::ACCEPTED_ID);
    $this->makeStyleDirectory(self::REJECTED_ID);

    // A tampered submission: the select never offered this id, because the
    // manager never parsed it.
    $formState = $this->dimensionsFormState(self::REJECTED_ID);
    $this->imageSettings()->validateSettingsForm($this->dimensionsForm(), $formState);

    $errors = $formState->getErrors();
    $this->assertCount(1, $errors, 'The unknown style is reported once, as a form error.');
    $this->assertStringContainsString(
      self::REJECTED_ID,
      (string) reset($errors),
      'The error names the value it refused.'
    );
    $this->assertSame(
      'dimensions][sm][style',
      array_key_first($errors),
      'And it is reported against the select that carried it.'
    );

    // A style the manager does know still fills the dimensions from it.
    $formState = $this->dimensionsFormState(self::ACCEPTED_ID);
    $this->imageSettings()->validateSettingsForm($this->dimensionsForm(), $formState);

    $this->assertSame([], $formState->getErrors(), 'A known style is no error at all.');
    $this->assertSame(300, $formState->getValue(['dimensions', 'sm', 'width']));
    $this->assertSame('', $formState->getValue(['dimensions', 'sm', 'height']));
    $this->assertFalse($formState->getValue(['dimensions', 'sm', 'exact']));
    $this->assertNull($formState->getValue(['dimensions', 'sm', 'style']), 'The id is never saved.');
  }

  /**
   * The settings plugin the admin form is built from.
   */
  private function imageSettings(): ImageSettings {
    $plugin = $this->container->get('plugin.manager.neo_settings')->createInstance('neo_image');
    \assert($plugin instanceof ImageSettings);
    return $plugin;
  }

  /**
   * A submitted dimensions row carrying one style id.
   *
   * @param string $style
   *   The id the select is claimed to have submitted.
   *
   * @return \Drupal\Core\Form\FormState
   *   The form state, with the row the validator reads.
   */
  private function dimensionsFormState(string $style): FormState {
    $formState = new FormState();
    $formState->setValue(['dimensions'], [
      'sm' => [
        'style' => $style,
        'settings' => [
          'width' => '',
          'height' => '',
          'exact' => 0,
          'bg' => '',
        ],
      ],
    ]);
    return $formState;
  }

  /**
   * The part of the built form the validator reports an error against.
   *
   * `#parents` is what `FormStateInterface::setError()` names an element by,
   * and the form builder sets it on every element on the way to validation. The
   * form is not rebuilt here — one row of it is enough, and building the real
   * one would drag in the whole breakpoint table for an assertion about one
   * select.
   *
   * @return array
   *   A form with the one element the criterion is about.
   */
  private function dimensionsForm(): array {
    return [
      '#parents' => [],
      'dimensions' => [
        'sm' => [
          'style' => [
            '#type' => 'select',
            '#parents' => ['dimensions', 'sm', 'style'],
          ],
        ],
      ],
    ];
  }

  /**
   * Creates one derivative directory under the public styles directory.
   *
   * @param string $name
   *   The directory name.
   *
   * @return string
   *   The uri of the created directory.
   */
  private function makeStyleDirectory(string $name): string {
    $uri = 'public://styles/' . $name;
    $this->container->get('file_system')->prepareDirectory(
      $uri,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
    $this->assertDirectoryExists($uri);
    return $uri;
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
