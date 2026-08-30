<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that a **built style** is built once per set of parameters.
 *
 * `getImageStyle()` created an unsaved `ImageStyle`, attached one effect per
 * parameter — running a preprocess callback for the effects that have one —
 * and appended the **format conversion** effect last, memoising none of it. Two
 * calls on one object with unchanged parameters did all of that twice.
 *
 * On the flush path that is the whole cost. Core flushes one image by loading
 * every configured image style and calling `flush($path)` on each; this
 * module's `hook_image_style_flush` answers every one of those by iterating
 * every neo style and asking it for a built style; and the style manager is a
 * shared service, so the same objects are asked over and over. Eight configured
 * styles and twenty-four neo directories is 192 constructions for one file.
 *
 * **The memo is a single slot keyed on the style id.** The id is computed on
 * every call already — it is the name the built style is given — so keying on
 * it costs nothing that was not already spent, and it invalidates itself: any
 * change through any setter, or through `setParameters()`, produces a different
 * id and misses the memo. There is deliberately no flag for a mutation site to
 * remember to clear, because the failure when one is forgotten is a style
 * rendered under a name describing different parameters.
 *
 * **The memoised style is shared, not cloned,** which is this change's one new
 * hazard and is accepted deliberately: every caller in and out of this module
 * uses it read-only, so one instance is safe, and cloning per call would return
 * most of the saving.
 *
 * **Which effects are free to a fixture and which are not.** Two ids the **id
 * grammar** admits — `f` and `fw`, the focal pair — name effects whose plugins
 * only a contributed module provides. Core does not defer creating them:
 * `addImageEffect()` hands the configuration to the style's plugin collection,
 * which instantiates the plugin there and then, so a fixture calling `focal()`
 * or `focalWidth()` raises `PluginNotFoundException` the first time it builds a
 * style unless `focal_point` — and `crop`, which it requires — is installed. A
 * style therefore cannot even be *built* under a name for an effect whose
 * plugin is absent, let alone rendered. That is why both modules are in the
 * list below and why this package declares them under `require-dev`: a dev
 * checkout is then guaranteed to have every module this class installs. Every
 * other effect built here — scale, resize, crop, crop-sides, the module's own
 * `exact` and the **format conversion** — is core's or this module's, and costs
 * a fixture nothing.
 *
 * **Why kernel and not unit.** A built style is an unsaved config entity: it
 * needs the entity type manager and the image effect plugin manager. Nothing
 * here is pure, so nothing here is a unit test.
 *
 * **Memoisation is asserted by identity,** because "the same instance" is the
 * contract being specified rather than a proxy for it. No spy, no construction
 * counter, no mock.
 */
#[Group('neo_image')]
final class BuiltStyleBuiltOnceTest extends KernelTestBase {

  /**
   * The neo directories the flush criterion puts on disk.
   *
   * Three well-formed ids, each a different effect, so the criterion is about
   * many styles rather than one repeated.
   */
  private const NEO_DIRECTORIES = [
    'neo-s--w-300',
    'neo-r--w-400_h-300',
    'neo-c--w-800_h-600_a-c',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'crop',
    'focal_point',
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
    $this->installConfig(['system', 'image', 'file', 'neo_image']);
  }

  /**
   * Two calls with unchanged parameters answer one instance.
   *
   * Acceptance criterion: it answers the same image style instance for two
   * calls with unchanged parameters.
   *
   * `assertSame` is the honest assertion here: it proves the second call
   * constructed nothing, which is the entire claim. A construction counter
   * would prove the same thing less directly and would need a seam the class
   * does not have.
   *
   * A third call is made after the second, because a memo that is written once
   * and then overwritten by its own reader passes a two-call test.
   */
  public function testItAnswersTheSameImageStyleInstanceForUnchangedParameters(): void {
    $style = (new NeoImageStyle())->scale(300);

    $first = $style->getImageStyle();
    $second = $style->getImageStyle();
    $third = $style->getImageStyle();

    $this->assertSame($first, $second, 'A second call with unchanged parameters constructs nothing.');
    $this->assertSame($first, $third, 'And neither does a third: reading the memo does not clear it.');
  }

  /**
   * Every mutation path answers a new instance, and re-arms the memo.
   *
   * Acceptance criterion: it answers a different image style instance after any
   * setter changes a parameter, and after parameters are replaced wholesale.
   *
   * The memo is keyed on the **style id**, so this is the criterion that says
   * why: every setter writes a parameter, every parameter is in the id, and a
   * different id misses the memo. Nothing has to remember to clear a flag —
   * which is the point, since there are nine mutation sites today and a tenth
   * arrives with every setter added later.
   *
   * Each setter is applied to the same object in turn, accumulating, so each
   * step really does change the id rather than replacing an identical one. The
   * memo is asserted to re-arm after every one of them: a memo that invalidates
   * but never refills is a memo that never worked.
   *
   * `auto()` gets its own object because it delegates to the setters below it,
   * and `setParameters()` closes the list because replacing the parameters
   * wholesale is the one mutation that goes nowhere near a setter.
   */
  public function testItAnswersDifferentInstancesAfterEveryMutation(): void {
    $style = (new NeoImageStyle())->scale(300);
    $previous = $style->getImageStyle();

    $mutations = [
      'size' => fn (NeoImageStyle $s) => $s->size(400, 300),
      'scale' => fn (NeoImageStyle $s) => $s->scale(600, 400),
      'scaleCrop' => fn (NeoImageStyle $s) => $s->scaleCrop(400, 300),
      'crop' => fn (NeoImageStyle $s) => $s->crop(200, 100, 'left-top'),
      'cropSides' => fn (NeoImageStyle $s) => $s->cropSides(),
      'focal' => fn (NeoImageStyle $s) => $s->focal(800, 600),
      'focalWidth' => fn (NeoImageStyle $s) => $s->focalWidth(120),
      'exact' => fn (NeoImageStyle $s) => $s->exact(500, 500, 'right-bottom', '#ffffff'),
    ];
    foreach ($mutations as $setter => $mutate) {
      $mutate($style);
      $built = $style->getImageStyle();

      $this->assertNotSame(
        $previous,
        $built,
        sprintf('%s() changed a parameter, so the memo is missed.', $setter)
      );
      $this->assertSame(
        $built,
        $style->getImageStyle(),
        sprintf('And the memo is re-armed for the parameters %s() left behind.', $setter)
      );
      $previous = $built;
    }

    // auto() delegates, so it is exercised on an object of its own.
    $auto = new NeoImageStyle();
    $auto->scale(300);
    $before = $auto->getImageStyle();
    $auto->auto(400, 300);
    $this->assertNotSame($before, $auto->getImageStyle(), 'auto() changed a parameter too.');

    // Replacing the parameters wholesale is the ninth mutation site.
    $wholesale = $style->setParameters(['s' => ['w' => 999]])->getImageStyle();
    $this->assertNotSame($previous, $wholesale, 'setParameters() replaces the parameters, so the memo is missed.');
    $this->assertSame($wholesale, $style->getImageStyle(), 'And the memo is re-armed for what it replaced them with.');
  }

  /**
   * The focal setters answer their contributed effect ids.
   *
   * Acceptance criterion: it answers the **contributed effect** ids its focal
   * setters produce.
   *
   * The mutation fixture above calls `focal()` and `focalWidth()`, so both
   * contributed effect ids really do reach the built style. Without this
   * criterion that is a property of what the class happens not to assert;
   * with it, it is the thing asserted, and the two contributed modules in the
   * list above are answered for rather than carried along.
   *
   * The parameter-to-effect map is read first because it is a pure function of
   * the parameters and reaches no plugin manager: it says which ids the setters
   * produce, and it would say so with neither module installed. The built style
   * is read second because that is where the plugins are created, which is the
   * part the declared dev dependencies pay for.
   */
  public function testItAnswersTheContributedEffectIdsItsFocalSettersProduce(): void {
    $expectedConversion = version_compare(\Drupal::VERSION, '11.2.0', '>=') ? 'image_convert_avif' : 'image_convert';

    $style = (new NeoImageStyle())->focal(800, 600)->focalWidth(120);

    $this->assertSame(
      ['focal_point_scale_and_crop', 'focal_point_crop_by_width'],
      array_keys($style->getImageStyleEffects()),
      'The parameter-to-effect map answers the contributed effect id each focal setter produces.'
    );
    $this->assertSame(
      ['focal_point_scale_and_crop', 'focal_point_crop_by_width', $expectedConversion],
      array_column(array_values($style->getImageStyle()->getEffects()->getConfiguration()), 'id'),
      'And both ids reach the built style, which is what needs their plugins installed.'
    );
  }

  /**
   * The style answers to the name its parameters serialise to.
   *
   * Acceptance criterion: it answers a style whose name still matches the style
   * id its parameters serialise to.
   *
   * This is the criterion the memo could break and must not: a stale style is
   * not a wrong instance, it is a style rendered under a name that describes
   * different parameters, and the derivative directory it writes to is named
   * after that name. It is checked at every step of a mutation sequence rather
   * than once, because a memo only goes stale after something changed.
   *
   * The name is compared against the codec's own serialisation rather than
   * against a literal, so the criterion stays true to what the id grammar
   * produces rather than to what this test remembers of it.
   */
  public function testItAnswersTheNameItsParametersSerialiseTo(): void {
    $style = new NeoImageStyle();
    $mutations = [
      fn (NeoImageStyle $s) => $s->scale(300),
      fn (NeoImageStyle $s) => $s->cropSides(),
      fn (NeoImageStyle $s) => $s->crop(200, 100, 'left-top'),
      fn (NeoImageStyle $s) => $s->setParameters(['e' => ['w' => 640, 'h' => 480]]),
    ];
    foreach ($mutations as $mutate) {
      $mutate($style);
      $expected = $style->convertParamsToId($style->getParameters());

      $this->assertSame(
        $expected,
        $style->getImageStyleName(),
        'The id the parameters serialise to is the name the style is asked for.'
      );
      $this->assertSame(
        $expected,
        $style->getImageStyle()->getName(),
        'And it is the name the style answers to.'
      );
      $this->assertSame(
        $expected,
        $style->getImageStyle()->getName(),
        'A memoised style answers under the name its own parameters serialise to, not an earlier one.'
      );
    }
  }

  /**
   * The conversion effect is still appended, last, and exactly once.
   *
   * Acceptance criterion: it still appends the core-appropriate
   * format-conversion effect, last, after every parameter effect.
   *
   * "Once per process" is not asserted and no criterion pretends to: a value
   * resolved on first use and reused leaves no observable trace inside one
   * process. What is observable is the effect itself, so that is what is
   * pinned — the right effect, in the right place, carrying the right data, for
   * the core version the tests are running on.
   *
   * The expected id is derived from `\Drupal::VERSION` here for the same reason
   * the class derives it: hard-coding one would pin the test to the core this
   * site runs rather than to the rule.
   *
   * Exactly one conversion effect is asserted alongside the order, because a
   * memo that hands back its own slot and appends to it again would grow one
   * conversion effect per call — a failure the ordering assertion alone would
   * not catch until the second call.
   */
  public function testItStillAppendsTheFormatConversionEffectLast(): void {
    $expectedConversion = version_compare(\Drupal::VERSION, '11.2.0', '>=') ? 'image_convert_avif' : 'image_convert';

    $style = (new NeoImageStyle())->scale(300);
    $style->cropSides();
    $style->exact(640, 480, 'right-bottom', '#ffffff');

    $built = $style->getImageStyle();
    $effects = array_values($built->getEffects()->getConfiguration());
    $ids = array_column($effects, 'id');

    $this->assertSame(
      ['image_scale', 'image_crop_sides', 'exact', $expectedConversion],
      $ids,
      'Every parameter effect is attached in order, and the conversion effect comes last.'
    );
    $this->assertSame(
      ['extension' => 'webp'],
      $effects[3]['data'],
      'The conversion effect still converts to webp.'
    );
    $this->assertTrue(
      $effects[0]['data']['upscale'],
      'The preprocess callback still runs for the effects that have one.'
    );
    $this->assertSame(
      'exact',
      $effects[2]['data']['canvas_size'],
      'And for the ones whose data it rewrites wholesale.'
    );

    // A second call answers the memo, not a slot with a second conversion
    // effect appended to it.
    $again = array_column(array_values($style->getImageStyle()->getEffects()->getConfiguration()), 'id');
    $this->assertSame($ids, $again, 'A second call answers one conversion effect, not two.');
    $this->assertCount(
      1,
      array_keys($again, $expectedConversion, TRUE),
      'The conversion effect is appended once, however many times the style is asked for.'
    );
  }

  /**
   * Every neo style answers one built style across a whole flush.
   *
   * Acceptance criterion: it answers the same built style for every neo style
   * across a flush that touches every configured image style.
   *
   * This is the payoff, asserted at the manager rather than through the hook.
   * Core's flush loads every configured image style and calls `flush($path)` on
   * each; every one of those invokes `hook_image_style_flush`, which iterates
   * every neo style the manager holds and asks each for a built style. Driving
   * core's flush from here to count constructions would be testing core, so the
   * shape is reproduced instead — configured styles on the outside, neo styles
   * on the inside — and what it asserts is what makes the hook cheap.
   *
   * Two facts together are the whole mechanism. The manager memoises its scan,
   * so every invocation gets the *same* neo style objects; and each of those
   * objects now memoises its built style. Constructions therefore fall from
   * configured × neo to neo, which on this site is 192 to 24.
   */
  public function testItAnswersTheSameBuiltStyleForEveryNeoStyleAcrossOneFlush(): void {
    foreach (self::NEO_DIRECTORIES as $name) {
      $this->makeStyleDirectory($name);
    }
    foreach (['flush_one', 'flush_two', 'flush_three'] as $name) {
      ImageStyle::create(['name' => $name, 'label' => $name])->save();
    }
    $configured = $this->container->get('entity_type.manager')
      ->getStorage('image_style')
      ->loadMultiple();
    $this->assertGreaterThanOrEqual(3, count($configured), 'The flush has more than one configured style to walk.');

    $manager = $this->container->get('neo_image.style_manager');
    $neoStyles = $manager->getStyles();
    $this->assertCount(count(self::NEO_DIRECTORIES), $neoStyles, 'Every neo directory on disk is a style.');

    $seen = [];
    foreach (array_keys($configured) as $configuredName) {
      // What the hook receives on each invocation: the manager is a shared
      // service and memoises its scan, so these are the same objects every
      // time.
      $this->assertSame(
        $neoStyles,
        $manager->getStyles(),
        sprintf('Flushing "%s" asks the manager for the same neo style objects.', $configuredName)
      );
      foreach ($manager->getStyles() as $id => $neoStyle) {
        $seen[$id][] = $neoStyle->getImageStyle();
      }
    }

    $this->assertSame(self::NEO_DIRECTORIES, array_keys($seen));
    $distinct = [];
    foreach ($seen as $id => $builtStyles) {
      $this->assertCount(count($configured), $builtStyles, 'Every configured style asked every neo style.');
      foreach ($builtStyles as $builtStyle) {
        $this->assertSame(
          $builtStyles[0],
          $builtStyle,
          sprintf('"%s" answered one built style for the whole flush.', $id)
        );
      }
      $this->assertSame($id, $builtStyles[0]->getName(), 'And it is named for the directory it came from.');
      $distinct[spl_object_id($builtStyles[0])] = TRUE;
    }
    $this->assertCount(
      count(self::NEO_DIRECTORIES),
      $distinct,
      'A whole flush constructs one style per neo style, not one per configured style per neo style.'
    );
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

}
