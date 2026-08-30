<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\image\ImageStyleInterface;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\NeoImageStyleManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the **directory style** and the manager's list of them.
 *
 * A **directory style** stands for a **derivative directory** rather than for a
 * set of parameters: it carries the directory's name and the **format
 * conversion**, and no other effect. Those two things are all core's
 * derivative-uri arithmetic reads off a style — the id, which becomes the
 * directory segment, and the extension the effects produce, which decides
 * whether a suffix is appended to the source path — so both answers are
 * available for any neo directory on disk from its name alone.
 *
 * **That is why it exists for a name the codec refuses.** The parameter effects
 * are exactly the part `buildUri()` never asks about, and they are also the
 * part that cannot be recovered from a **rejected id**. Dropping them is what
 * lets a directory be addressed whether or not the grammar admits its name —
 * which is the rule ADR 0015 records.
 *
 * **The equivalence is the load-bearing assertion here.** If a directory
 * style's uri ever differed from the parsed style's **built style**, the flush
 * built on it would delete nothing and report success. So it is asserted across
 * the effect kinds the module produces and across both branches of core's
 * scheme choice: a source in the default scheme, a source in another writable
 * scheme, and a source already carrying the derivative's own extension, where
 * nothing is appended.
 *
 * **The list is uniform and memoised.** Every name the **style scan** listed
 * gets a directory style, parseable or not — a parsed-where-possible list would
 * put the **codec** back on a path that runs on every file move to produce a
 * list whose two halves behave identically. It is remembered for the request
 * beside the two memos the manager already keeps, because core invokes the
 * **per-file flush** once per *configured* image style, so an unmemoised list
 * would rebuild every unsaved config entity once per configured style for one
 * file.
 *
 * **Why kernel and not unit.** Every subject here creates an unsaved
 * `ImageStyle`, which needs the entity type manager and the image effect plugin
 * manager, or walks real stream wrappers. There is no pure seam: the subject is
 * the uri core computes, and mocking that away would leave nothing to assert.
 */
#[Group('neo_image')]
final class DirectoryStyleAddressesByNameTest extends KernelTestBase {

  /**
   * A scale directory, well-formed.
   */
  private const SCALE_ID = 'neo-s--w-300';

  /**
   * A crop directory, well-formed.
   */
  private const CROP_ID = 'neo-c--w-800_h-600_a-c';

  /**
   * An exact directory, well-formed: the module's own effect.
   */
  private const EXACT_ID = 'neo-e--w-640_h-480';

  /**
   * A directory created after the first call in a request.
   */
  private const LATE_ID = 'neo-r--w-400_h-300';

  /**
   * An id the grammar refuses: `z` is not a property key.
   */
  private const REJECTED_ID = 'neo-s--z-1';

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
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // A second writable scheme for the equivalence assertion. Without a path in
    // settings core registers no private stream wrapper at all, and the branch
    // of `buildUri()` that keeps a writable source's own scheme would never be
    // exercised.
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Core registers this only when the setting was already present as the
    // container was built. Registering it here as well is what core's own file
    // tests do, and it makes the fixture independent of that ordering.
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

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
   * A directory style is the name plus the format conversion, and no more.
   *
   * Acceptance criterion: it builds a style named for the directory, carrying
   * the format conversion and no other effect.
   *
   * The id is asserted because it is the directory segment `buildUri()` writes,
   * and the single effect is asserted because everything else about a neo style
   * is parameters — the part the uri arithmetic never reads and the part a
   * **rejected id** cannot supply.
   *
   * The **identity style**'s **built style** is built beside it rather than the
   * conversion effect's plugin id being written out here. A literal would be a
   * third copy of the `\Drupal::VERSION` choice this module makes once, and a
   * copy that agreed with the module only until core's convert effect changed
   * again; asking the identity style what it appends compares the two builders
   * against each other, which is the claim.
   */
  public function testItBuildsOneStyleNamedForTheDirectoryCarryingOnlyTheFormatConversion(): void {
    $style = NeoImageStyle::buildDirectoryStyle(self::CROP_ID);

    $this->assertInstanceOf(ImageStyleInterface::class, $style);
    $this->assertSame(self::CROP_ID, $style->id(), 'The directory name is the style id.');

    $effects = $this->effectPluginIds($style);
    $this->assertCount(1, $effects, 'A directory style carries one effect, not the crop its name describes.');

    $identity = (new NeoImageStyle())->getImageStyle();
    $this->assertSame(
      $this->effectPluginIds($identity),
      $effects,
      'It is the identity style built style wearing the directory name.'
    );
    $this->assertSame(
      $identity->getDerivativeExtension('jpg'),
      $style->getDerivativeExtension('jpg'),
      'And it answers the same derivative extension, which is the second thing buildUri() reads.'
    );

    $configuration = $style->getEffects()->getIterator()->current()->getConfiguration();
    $this->assertSame('webp', $configuration['data']['extension'], 'The format conversion is configured as the module configures it.');
  }

  /**
   * A name the codec refuses is still a directory this module wrote.
   *
   * Acceptance criterion: it builds a directory style for a name the codec
   * refuses, without parsing it and without logging.
   *
   * This is the whole reason the object exists. The fixture is asserted to be
   * genuinely outside the **id grammar** first, because a criterion about a
   * refused name is vacuous if the name happens to be admitted — and the
   * grammar's boundary has moved once already, when the bare prefix became the
   * **identity style**.
   *
   * The absence of a log record is asserted rather than argued. `getStyles()`
   * names a directory it cannot parse on the module's own channel, once per
   * request, which is right for a person looking at an admin form and wrong for
   * a path that runs on every file move; a builder that reached for the codec
   * to decide anything would leave that record behind.
   */
  public function testItBuildsOneDirectoryStyleForTheNameTheCodecRefusesWithoutParsingOrLogging(): void {
    $codec = new NeoImageStyle();
    try {
      $codec->convertIdToParams(self::REJECTED_ID);
      $this->fail(sprintf('The fixture "%s" must be a name the codec refuses.', self::REJECTED_ID));
    }
    catch (\InvalidArgumentException) {
      // The refusal is the fixture's premise, not the subject.
    }

    $style = NeoImageStyle::buildDirectoryStyle(self::REJECTED_ID);

    $this->assertSame(self::REJECTED_ID, $style->id(), 'The refused name is addressable all the same.');
    $this->assertCount(1, $this->effectPluginIds($style), 'It carries the format conversion, as every directory style does.');
    $this->assertSame(
      'public://styles/' . self::REJECTED_ID . '/public/cats/fixture.jpg.' . $style->getDerivativeExtension('jpg'),
      $style->buildUri('public://cats/fixture.jpg'),
      'And it answers a derivative uri inside the directory it names.'
    );

    $this->assertCount(0, $this->neoImageLogRecords(), 'Building one says nothing on the module channel.');
  }

  /**
   * Every admitted name answers the uri the parsed style already answered.
   *
   * Acceptance criterion: it answers the same derivative uri as the parsed
   * style's built style, for every name the codec admits.
   *
   * The plan's central safety claim, and the one thing here that fails
   * silently: a flush computing a uri nothing writes deletes nothing and
   * reports success. So it is asserted rather than argued, over both inputs
   * `buildUri()` actually reads.
   *
   * The **names** cover the effect kinds the module produces from core's own
   * effects and its own, plus the **identity style**, whose **built style** the
   * directory style already is. The **sources** cover both branches of core's
   * scheme choice and both branches of its extension choice: the default
   * scheme, another writable scheme — which a derivative inherits rather than
   * falling back from — and a source already carrying the derivative's own
   * extension, where nothing is appended.
   *
   * That last extension is read off the style rather than written down, because
   * core's convert effect answers `avif` where the toolkit supports it and the
   * configured fallback where it does not. Writing `webp` here would pin the
   * container's toolkit rather than the equivalence.
   */
  public function testItAnswersTheSameDerivativeUriAsTheParsedStylesBuiltStyle(): void {
    $names = [
      'the identity style' => 'neo-',
      'a scale' => self::SCALE_ID,
      'a resize' => self::LATE_ID,
      'a crop' => self::CROP_ID,
      'a scale and crop' => 'neo-sc--w-800_h-600_a-c',
      'a crop sides' => 'neo-cs',
      'an exact' => self::EXACT_ID,
    ];

    foreach ($names as $kind => $name) {
      $parsed = new NeoImageStyle();
      $parsed->setParameters($parsed->convertIdToParams($name));
      $built = $parsed->getImageStyle();
      $this->assertSame($name, $built->id(), sprintf('%s round-trips through the codec.', $kind));

      $directory = NeoImageStyle::buildDirectoryStyle($name);

      $extension = $built->getDerivativeExtension('jpg');
      $sources = [
        'the default scheme' => 'public://cats/fixture.jpg',
        'another writable scheme' => 'private://cats/fixture.jpg',
        'the derivative extension already' => 'public://cats/fixture.' . $extension,
      ];

      foreach ($sources as $branch => $source) {
        $this->assertSame(
          $built->buildUri($source),
          $directory->buildUri($source),
          sprintf('%s, from a source in %s, is addressed identically.', $kind, $branch)
        );
      }
    }

    // The three source shapes really are three different answers, or the loop
    // above would agree for reasons that have nothing to do with the subject.
    $probe = NeoImageStyle::buildDirectoryStyle(self::SCALE_ID);
    $extension = $probe->getDerivativeExtension('jpg');
    $uris = [
      $probe->buildUri('public://cats/fixture.jpg'),
      $probe->buildUri('private://cats/fixture.jpg'),
      $probe->buildUri('public://cats/fixture.' . $extension),
    ];
    $this->assertSame($uris, array_unique($uris), 'The three source shapes exercise three different answers.');
    $this->assertStringStartsWith('private://', $uris[1], 'A writable source scheme is inherited rather than fallen back from.');
    $this->assertStringEndsWith('/cats/fixture.' . $extension, $uris[2], 'A source already in the derivative extension gains no suffix.');
  }

  /**
   * One directory style per name, and the same objects for the whole request.
   *
   * Acceptance criterion: it answers one directory style per name on disk, and
   * the same instances on a second call.
   *
   * The list is **uniform**: a name the codec refuses gets a directory style
   * beside the ones it admits, which is the difference between this list and
   * `getStyles()` and the entire point of the surface. A parsed-where-possible
   * list would put the codec back on a path that runs on every file move to
   * produce halves that behave identically.
   *
   * Identity, not equality, because the memo is what stops the cost. Core
   * invokes the **per-file flush** once per *configured* image style, so an
   * unmemoised list rebuilds every unsaved config entity once per configured
   * style for a single file — the shape the previous batch removed. A third
   * call follows the second, because a memo written once and overwritten by its
   * own reader passes a two-call test.
   */
  public function testItAnswersOneDirectoryStylePerNameAndTheSameInstancesTwice(): void {
    $this->makeStyleDirectory(self::SCALE_ID);
    $this->makeStyleDirectory(self::CROP_ID);
    $this->makeStyleDirectory(self::REJECTED_ID);

    $manager = $this->container->get('neo_image.style_manager');

    $styles = $manager->getDirectoryStyles();
    $keys = array_keys($styles);
    sort($keys);
    $this->assertSame(
      [self::CROP_ID, self::SCALE_ID, self::REJECTED_ID],
      $keys,
      'Every name on disk gets one, whether or not the codec admits it.'
    );

    foreach ($styles as $name => $style) {
      $this->assertInstanceOf(ImageStyleInterface::class, $style);
      $this->assertSame($name, $style->id(), 'Each is keyed by the name it carries.');
    }

    foreach ([$manager->getDirectoryStyles(), $manager->getDirectoryStyles()] as $again) {
      foreach ($styles as $name => $style) {
        $this->assertSame($style, $again[$name], sprintf('"%s" is one object for the whole request.', $name));
      }
    }

    $this->assertCount(0, $this->neoImageLogRecords(), 'Listing them consults no grammar and says nothing.');
  }

  /**
   * The styles and the directory styles cost one listing between them.
   *
   * Acceptance criterion: it lists the style directories once across the styles
   * and the directory styles.
   *
   * Both answers come out of the **style scan**, which is memoised for the
   * request, so whichever question is asked first fixes what both of them say.
   * A directory created between the two calls is the probe: it is the only way
   * to tell a listing that was remembered from a listing that was repeated and
   * happened to agree.
   *
   * Both orders are exercised because they fail differently, and the second
   * needs a manager whose scan has not run — the shared service cannot offer
   * that twice in one test.
   */
  public function testItListsTheStyleDirectoriesOnceAcrossStylesAndDirectoryStyles(): void {
    $this->makeStyleDirectory(self::SCALE_ID);
    $this->makeStyleDirectory(self::CROP_ID);

    // Styles first, then directory styles.
    $manager = $this->container->get('neo_image.style_manager');
    $keys = array_keys($manager->getStyles());
    sort($keys);
    $this->assertSame([self::CROP_ID, self::SCALE_ID], $keys);

    $this->makeStyleDirectory(self::LATE_ID);

    $keys = array_keys($manager->getDirectoryStyles());
    sort($keys);
    $this->assertSame(
      [self::CROP_ID, self::SCALE_ID],
      $keys,
      'The directory styles come from the names already listed, not from a second reading.'
    );

    // Directory styles first, then names and styles, on a scan that has not
    // run.
    $fresh = $this->freshManager();
    $keys = array_keys($fresh->getDirectoryStyles());
    sort($keys);
    $this->assertSame([self::CROP_ID, self::LATE_ID, self::SCALE_ID], $keys);

    $this->makeStyleDirectory(self::EXACT_ID);

    $names = $fresh->getStyleNames();
    sort($names);
    $this->assertSame(
      [self::CROP_ID, self::LATE_ID, self::SCALE_ID],
      $names,
      'Building the directory styles is what listed the names, so asking for them reads nothing more.'
    );
    $keys = array_keys($fresh->getStyles());
    sort($keys);
    $this->assertSame([self::CROP_ID, self::LATE_ID, self::SCALE_ID], $keys, 'And neither does parsing the styles.');
  }

  /**
   * The plugin ids of a style's effects, in order.
   *
   * @param \Drupal\image\ImageStyleInterface $style
   *   The style.
   *
   * @return string[]
   *   The effect plugin ids.
   */
  private function effectPluginIds(ImageStyleInterface $style): array {
    $ids = [];
    foreach ($style->getEffects() as $effect) {
      $ids[] = $effect->getPluginId();
    }
    return $ids;
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
   * A manager of its own, whose scan has not run yet.
   *
   * @return \Drupal\neo_image\NeoImageStyleManager
   *   The manager, built from the same services the container gives the shared
   *   one.
   */
  private function freshManager(): NeoImageStyleManager {
    return new NeoImageStyleManager(
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('file_system'),
      $this->container->get('logger.channel.neo_image')
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
