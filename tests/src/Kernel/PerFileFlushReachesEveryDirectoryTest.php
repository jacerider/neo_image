<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the **per-file flush** reaching every directory this module wrote.
 *
 * The module has two flushes and only one of them had learned the lesson. The
 * **style flush** deletes **derivative directories** by *name*, so a directory
 * holding a **rejected id** stays removable even though nothing can parse it.
 * The per-file flush iterated the parsed styles instead — and `getStyles()` is
 * precisely the reader that *skips* a name the **codec** refuses — so the two
 * halves of the module disagreed about whether such a directory existed.
 *
 * **What that cost.** Core invokes `hook_image_style_flush()` once per
 * configured image style whenever a file is moved or deleted, through
 * `ImageDerivativeUtilities::pathFlush()`. The derivative the hook failed to
 * remove therefore usually outlived the file it was made from, in the one kind
 * of directory nothing else visits, until somebody pressed a button on an admin
 * form they may never open. Iterating the manager's **directory styles** — one
 * per name on disk, parseable or not — is what closes that gap. See ADR 0015.
 *
 * **The hook is driven the way the site drives it.** Every test here calls
 * `ImageStyle::flush($path)` on a *configured* style, which is what core's
 * `pathFlush()` calls, so the hook's registration and signature are pinned
 * along with its body rather than the function being invoked by hand.
 *
 * **The derivative asserted to survive belongs to a second configured style.**
 * `flush($path)` deletes `$this->buildUri($path)` itself, before it invokes the
 * hook, so asserting the *driving* style's own derivative survives would be a
 * red caused by core rather than by this module.
 *
 * **Why kernel and not unit.** The subject is the uri core computes and the
 * tree a real stream wrapper holds: the hook builds every uri through core's
 * `buildUri()` and then walks the directories the deletion emptied. Mocking the
 * filesystem away would leave nothing worth asserting.
 *
 * `RejectedIdConsumerReactionsTest` covers the other flush — the admin button —
 * and the two other consumers of a **rejected id**.
 */
#[Group('neo_image')]
final class PerFileFlushReachesEveryDirectoryTest extends KernelTestBase {

  /**
   * The source image whose derivatives are flushed.
   */
  private const SOURCE = 'public://cats/fixture.jpg';

  /**
   * The flushed source's path inside its scheme.
   */
  private const TARGET = 'cats/fixture.jpg';

  /**
   * A second source, never flushed, whose derivatives sit beside the first's.
   *
   * It is what keeps a directory from emptying, which is how a derivative that
   * was *deleted* is told apart from one that vanished with its directory.
   */
  private const SIBLING_TARGET = 'cats/other.jpg';

  /**
   * A well-formed directory that keeps a sibling derivative, and survives.
   */
  private const SCALE_ID = 'neo-s--w-300';

  /**
   * A well-formed directory holding nothing else, which the deletion empties.
   */
  private const CROP_ID = 'neo-c--w-800_h-600_a-c';

  /**
   * A refused directory that keeps a sibling derivative, and survives.
   *
   * `z` is not a property key, so the **codec** throws on this name.
   */
  private const REJECTED_ID = 'neo-s--z-1';

  /**
   * A refused directory holding nothing else, which the deletion empties.
   *
   * It names a width and gives it no value, so the **codec** throws on it too.
   */
  private const REJECTED_EMPTIED_ID = 'neo-s--w';

  /**
   * The configured style the flush is driven through.
   */
  private const DRIVING_STYLE = 'neo_image_driving_style';

  /**
   * A second configured style, whose derivative the flush must leave alone.
   */
  private const BYSTANDER_STYLE = 'neo_image_bystander_style';

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
   * A file's derivative goes from a directory whose name the codec refuses.
   *
   * Acceptance criterion: it deletes one file's derivative from a directory
   * whose name the codec refuses.
   *
   * The refusal is asserted as the fixture's premise rather than assumed: a
   * criterion about a refused name is vacuous if the name happens to be
   * admitted, and the **id grammar** has moved its boundary once already, when
   * the bare prefix became the **identity style**.
   *
   * The directory holds a second derivative that is not flushed, so it survives
   * the pruning walk. That is deliberate — without it, "the derivative is gone"
   * would also be satisfied by the directory disappearing around it, and the
   * criterion is about the deletion.
   */
  public function testItDeletesOneFilesDerivativeFromTheDirectoryTheCodecRefuses(): void {
    $this->assertCodecRefuses(self::REJECTED_ID);
    $this->seedDerivatives();

    $flushed = $this->neoDerivativeUri(self::REJECTED_ID, self::TARGET);
    $kept = $this->neoDerivativeUri(self::REJECTED_ID, self::SIBLING_TARGET);
    $this->assertFileExists($flushed);

    $this->drivingStyle()->flush(self::SOURCE);

    $this->assertFileDoesNotExist($flushed, 'A refused name is still a directory this module wrote, and its derivative is removable.');
    $this->assertFileExists($kept, 'Only the flushed file leaves; the directory is not emptied wholesale.');
    $this->assertDirectoryExists('public://styles/' . self::REJECTED_ID, 'And a directory that still holds something stays.');
  }

  /**
   * Every well-formed directory loses the same derivative it always lost.
   *
   * Acceptance criterion: it still deletes that file's derivative from every
   * well-formed style's directory.
   *
   * The regression guard on the change. A **directory style** and the parsed
   * style's **built style** agree on both inputs core's `buildUri()` reads, so
   * the string is unchanged for every admitted name — but a flush computing a
   * uri nothing writes deletes nothing and *reports success*, which is the one
   * failure here nobody would see. So it is asserted rather than argued, over
   * both a directory the deletion empties and one it does not.
   */
  public function testItStillDeletesThatFilesDerivativeFromEveryWellFormedStylesDirectory(): void {
    $this->seedDerivatives();

    $scale = $this->neoDerivativeUri(self::SCALE_ID, self::TARGET);
    $crop = $this->neoDerivativeUri(self::CROP_ID, self::TARGET);
    $this->assertFileExists($scale);
    $this->assertFileExists($crop);

    $this->drivingStyle()->flush(self::SOURCE);

    $this->assertFileDoesNotExist($scale, 'A well-formed directory that survives still loses the derivative.');
    $this->assertFileDoesNotExist($crop, 'And so does one the deletion empties.');
    $this->assertFileExists(
      $this->neoDerivativeUri(self::SCALE_ID, self::SIBLING_TARGET),
      'A derivative of another source is not this file\'s and is left alone.'
    );
  }

  /**
   * A directory the deletion empties is removed, and the walk stops at styles.
   *
   * Acceptance criterion: it removes a derivative directory the deletion leaves
   * empty, and stops at the styles directory.
   *
   * This is what makes a refused directory clear itself out as its contents go,
   * rather than waiting for an admin — so both an admitted and a refused name
   * are asserted, and the refused one is the half the flush could not reach
   * before.
   *
   * Two boundaries are asserted with it. The wrapper's own `styles` directory
   * survives, because that is where the walk stops. And the *configured*
   * driving style's directory survives although core's own deletion emptied it,
   * because the walk climbs only from the derivatives this module addressed —
   * a configured style's empty directory is core's business, not this hook's.
   */
  public function testItRemovesTheDerivativeDirectoryTheDeletionEmptiesAndStopsAtTheStyles(): void {
    $this->assertCodecRefuses(self::REJECTED_EMPTIED_ID);
    $this->seedDerivatives();

    $this->drivingStyle()->flush(self::SOURCE);

    $this->assertDirectoryDoesNotExist('public://styles/' . self::CROP_ID, 'An emptied well-formed directory goes with its last derivative.');
    $this->assertDirectoryDoesNotExist('public://styles/' . self::REJECTED_EMPTIED_ID, 'And so does an emptied refused one.');

    $this->assertDirectoryExists('public://styles/' . self::SCALE_ID, 'A directory that still holds a derivative stays.');
    $this->assertDirectoryExists('public://styles', 'The walk stops at the writable wrapper\'s styles directory.');
    $this->assertDirectoryExists(
      'public://styles/' . self::DRIVING_STYLE,
      'And it never climbs out of a neo directory into a configured style\'s, empty or not.'
    );
  }

  /**
   * A second configured style's derivative is none of this module's business.
   *
   * Acceptance criterion: it leaves a second configured style's derivative
   * alone — not the driving style's own.
   *
   * The distinction is load-bearing. Core's `flush($path)` deletes
   * `$this->buildUri($path)` itself, before it invokes the hook, so the driving
   * style's own derivative is gone whatever this module does — asserting *that*
   * one survives would fail on core's behaviour rather than on this one's. The
   * bystander is a configured style nothing flushed, and its derivative is the
   * honest subject.
   *
   * The driving style's derivative is asserted gone all the same, as the proof
   * that the flush really ran: every other assertion in this class would pass
   * vacuously against a flush that never happened.
   */
  public function testItLeavesTheSecondConfiguredStylesDerivativeAlone(): void {
    $this->seedDerivatives();

    $driving = $this->drivingStyle()->buildUri(self::SOURCE);
    $bystander = $this->bystanderStyle()->buildUri(self::SOURCE);
    $this->assertFileExists($driving);
    $this->assertFileExists($bystander);

    $this->drivingStyle()->flush(self::SOURCE);

    $this->assertFileDoesNotExist($driving, 'Core deletes the driving style\'s own derivative, which is how we know the flush ran.');
    $this->assertFileExists($bystander, 'A second configured style\'s derivative is not this module\'s to remove.');
    $this->assertDirectoryExists('public://styles/' . self::BYSTANDER_STYLE);
  }

  /**
   * Flushing one file names no directory on the module's channel.
   *
   * Acceptance criterion: it says nothing on the module's log channel while
   * flushing one file.
   *
   * The warning `getStyles()` writes for a directory it cannot parse is right
   * for a person looking at an admin form and wrong for a path that runs on
   * every file move and delete: core invokes this hook once per configured
   * image style, so one deleted file wrote it once per request for as long as
   * the junk was on disk. The flush no longer parses anything, so the warning
   * keeps only the two producers where somebody is looking at a form.
   *
   * The fixture carries two refused directories, so a flush that still parsed
   * would have something to complain about.
   */
  public function testItSaysNothingOnTheModulesLogChannelWhileFlushingOneFile(): void {
    $this->seedDerivatives();
    $this->assertCount(0, $this->neoImageLogRecords(), 'Nothing has been logged before the flush.');

    $this->drivingStyle()->flush(self::SOURCE);

    $this->assertSame([], $this->neoImageLogRecords(), 'Deleting one file\'s derivatives reads no name and reports none.');
  }

  /**
   * A whole-style flush and a temporary path are still declined.
   *
   * Acceptance criterion: it deletes nothing of this module's when a whole
   * configured style is flushed, or the path is temporary.
   *
   * Both guards keep every line they had. A flush with no path is core clearing
   * one *configured* style, which is not a reason to delete this module's
   * directories — the admin button is the surface for that. A `temporary://`
   * path is skipped as it always was.
   *
   * Core's own half of each is left to core and not asserted here: the
   * whole-style flush removes the driving style's directory itself, which is
   * exactly the behaviour this hook declines to imitate.
   */
  public function testItDeletesNothingOfThisModulesWhenTheWholeStyleIsFlushedOrThePathIsTemporary(): void {
    $this->seedDerivatives();
    $before = $this->neoDerivativeTree();

    $this->drivingStyle()->flush();
    $this->assertSame($before, $this->neoDerivativeTree(), 'Flushing a whole configured style leaves every neo directory as it was.');

    $this->drivingStyle()->flush('temporary://' . self::TARGET);
    $this->assertSame($before, $this->neoDerivativeTree(), 'And a temporary path is skipped before anything is addressed.');
  }

  /**
   * Asserts a name really is outside the id grammar.
   *
   * @param string $name
   *   The **derivative directory** name.
   */
  private function assertCodecRefuses(string $name): void {
    try {
      (new NeoImageStyle())->convertIdToParams($name);
      $this->fail(sprintf('The fixture "%s" must be a name the codec refuses.', $name));
    }
    catch (\InvalidArgumentException) {
      // The refusal is the fixture's premise, not the subject.
    }
  }

  /**
   * Writes every derivative the tests flush against.
   *
   * Four **derivative directories** — two the **codec** admits and two it
   * refuses — and two configured styles' directories beside them. One admitted
   * and one refused directory keep a second source's derivative, so they
   * outlive the pruning walk; the other two hold the flushed derivative alone,
   * so the walk empties them.
   */
  private function seedDerivatives(): void {
    foreach ([self::SCALE_ID, self::REJECTED_ID] as $name) {
      $this->writeFile($this->neoDerivativeUri($name, self::TARGET));
      $this->writeFile($this->neoDerivativeUri($name, self::SIBLING_TARGET));
    }
    foreach ([self::CROP_ID, self::REJECTED_EMPTIED_ID] as $name) {
      $this->writeFile($this->neoDerivativeUri($name, self::TARGET));
    }
    $this->writeFile($this->drivingStyle()->buildUri(self::SOURCE));
    $this->writeFile($this->bystanderStyle()->buildUri(self::SOURCE));
  }

  /**
   * The uri of a derivative inside one of this module's directories.
   *
   * Written out rather than asked of a **directory style**, because a fixture
   * built by the subject agrees with it however wrong both are. The one part
   * that is asked for is the extension, which core's convert effect answers
   * from the running toolkit — and it is asked of the **identity style**'s
   * **built style**, the surface that predates this change.
   *
   * @param string $name
   *   The **derivative directory** name.
   * @param string $target
   *   The source's path inside its scheme.
   *
   * @return string
   *   The derivative uri.
   */
  private function neoDerivativeUri(string $name, string $target): string {
    $extension = (new NeoImageStyle())->getImageStyle()->getDerivativeExtension('jpg');
    return 'public://styles/' . $name . '/public/' . $target . '.' . $extension;
  }

  /**
   * Every file and directory this module wrote under the styles directory.
   *
   * @return string[]
   *   The uris, sorted, with the configured styles' trees excluded.
   */
  private function neoDerivativeTree(): array {
    $found = [];
    foreach ([self::SCALE_ID, self::CROP_ID, self::REJECTED_ID, self::REJECTED_EMPTIED_ID] as $name) {
      $directory = 'public://styles/' . $name;
      if (!is_dir($directory)) {
        continue;
      }
      $found[] = $directory;
      $files = $this->container->get('file_system')->scanDirectory($directory, '/.*/');
      $found = array_merge($found, array_keys($files));
    }
    sort($found);
    return $found;
  }

  /**
   * The configured style the flush is driven through.
   *
   * @return \Drupal\image\ImageStyleInterface
   *   The style, created on first use.
   */
  private function drivingStyle(): ImageStyleInterface {
    return $this->configuredStyle(self::DRIVING_STYLE);
  }

  /**
   * A second configured style, which nothing flushes.
   *
   * @return \Drupal\image\ImageStyleInterface
   *   The style, created on first use.
   */
  private function bystanderStyle(): ImageStyleInterface {
    return $this->configuredStyle(self::BYSTANDER_STYLE);
  }

  /**
   * Loads a configured image style, saving it the first time it is asked for.
   *
   * @param string $name
   *   The style's machine name.
   *
   * @return \Drupal\image\ImageStyleInterface
   *   The style.
   */
  private function configuredStyle(string $name): ImageStyleInterface {
    $style = ImageStyle::load($name);
    if (!$style instanceof ImageStyleInterface) {
      $style = ImageStyle::create(['name' => $name, 'label' => $name]);
      $style->save();
    }
    return $style;
  }

  /**
   * Writes one placeholder derivative, creating its directory.
   *
   * @param string $uri
   *   The file uri.
   */
  private function writeFile(string $uri): void {
    $file_system = $this->container->get('file_system');
    $directory = $file_system->dirname($uri);
    $file_system->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
    file_put_contents($uri, 'derivative');
    $this->assertFileExists($uri);
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
