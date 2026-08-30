<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\NeoImageStyleManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the **style scan** reads once and holds nothing open.
 *
 * The manager keeps no stored registry — the **derivative directories** are the
 * registry — so it lists every writable stream wrapper's `styles/` directory
 * and parses each `neo-` name into a style. That listing is correct and stays.
 * Two things about how it was done were not.
 *
 * **It opened a directory handle and never closed it.** `opendir()` without a
 * matching `closedir()` left an open stream resource for the rest of the
 * request, once per writable wrapper per listing. `scandir()` removes the
 * resource rather than adding an obligation the next early return can skip, it
 * works over stream wrappers, and it leaves the `neo-` prefix filter alone.
 *
 * **It was walked more than once for one request's questions.** The parsed
 * styles are what the image settings form needs; the raw names are what the
 * **style flush** needs, and after the **id grammar** landed the name list is
 * load-bearing — it is how a directory holding a **rejected id** stays
 * deletable. They are one listing now: the names are memoised for the request
 * and the styles are derived from them, so a caller asking for names and a
 * caller asking for styles cost one directory read between them.
 *
 * **Both memos are per request, deliberately.** The listing *is* the registry
 * and it changes whenever any request writes a derivative for a size that did
 * not exist before, so a persistent cache would need invalidating from core's
 * derivative-writing path and would fail as a style missing from an admin
 * select or a flush that skipped a directory.
 *
 * **A directory appearing mid-request is how the memo is observed.** Creating
 * one between two calls and asserting it is *not* answered is the only way to
 * tell a listing that was remembered from a listing that was repeated and
 * happened to agree. It is not a claim that a request should miss new
 * directories for its own sake; it is the visible edge of the memo the plan
 * asks for, and the request that wrote the directory is the one that already
 * knows about it.
 *
 * **Why kernel and not unit.** The scan walks real stream wrappers and reads
 * real directories, so nothing here is pure. The absent handle is asserted by
 * counting stream resources against a **baseline**: one full scan first, the
 * count taken after it, then several further scans that may not push the count
 * past it. Counting either side of a single scan and demanding equality would
 * say *nothing was allocated* rather than *nothing leaked*, and a mechanism
 * that opens one resource and holds it for the request leaks nothing at all. A
 * leak is unbounded growth, and growth is what a baseline measures.
 * `get_resources('stream')` stays the instrument: deterministic inside one
 * process, needing nothing installed, and saying nothing about which function
 * read the directory.
 */
#[Group('neo_image')]
final class StyleScanReadsOnceTest extends KernelTestBase {

  /**
   * A scale directory, well-formed.
   */
  private const SCALE_ID = 'neo-s--w-300';

  /**
   * A crop directory, well-formed.
   */
  private const CROP_ID = 'neo-c--w-800_h-600_a-c';

  /**
   * An exact directory, well-formed.
   */
  private const EXACT_ID = 'neo-e--w-640_h-480';

  /**
   * A fourth directory, created after the first call in a request.
   */
  private const LATE_ID = 'neo-r--w-400_h-300';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
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
   * The names and the styles come out of one listing, in either order.
   *
   * Acceptance criterion: it answers the style names and the style objects from
   * a single directory listing.
   *
   * Asked in either order, the second question must not reach the filesystem.
   * A directory created between the two is the probe: one listing answers both,
   * so whichever question was asked first fixes what both of them say.
   *
   * Both orders are exercised because they fail differently. Names-then-styles
   * is the settings form followed by the flush; styles-then-names is the flush
   * hook followed by the flush button. A manager built by hand gives the second
   * order a scan that has not run yet, which the shared service cannot offer
   * twice in one test.
   */
  public function testItAnswersNamesAndStylesFromOneListing(): void {
    $this->makeStyleDirectory(self::SCALE_ID);
    $this->makeStyleDirectory(self::CROP_ID);

    // Names first, then styles.
    $manager = $this->container->get('neo_image.style_manager');
    $names = $manager->getStyleNames();
    sort($names);
    $this->assertSame([self::CROP_ID, self::SCALE_ID], $names);

    $this->makeStyleDirectory(self::LATE_ID);

    $keys = array_keys($manager->getStyles());
    sort($keys);
    $this->assertSame(
      [self::CROP_ID, self::SCALE_ID],
      $keys,
      'The styles are parsed from the names already listed, not from a second reading of the directory.'
    );

    // Styles first, then names, on a manager whose scan has not run.
    $fresh = $this->freshManager();
    $keys = array_keys($fresh->getStyles());
    sort($keys);
    $this->assertSame([self::CROP_ID, self::LATE_ID, self::SCALE_ID], $keys);

    $this->makeStyleDirectory(self::EXACT_ID);

    $names = $fresh->getStyleNames();
    sort($names);
    $this->assertSame(
      [self::CROP_ID, self::LATE_ID, self::SCALE_ID],
      $names,
      'Parsing the styles is what listed the names, so asking for the names reads nothing more.'
    );
  }

  /**
   * Scanning again holds no more stream resources than the first scan did.
   *
   * Acceptance criterion: repeated scans hold no more stream resources than
   * the first scan did.
   *
   * This is the one criterion in the class whose subject is the absence of
   * something, so it is written as a count rather than as a claim about which
   * function was called: `get_resources('stream')` is deterministic, needs
   * nothing installed, and says nothing about how the directory was read.
   *
   * **The baseline is taken after one full scan, not before it.** Before it,
   * the first scan's own allocation is what the assertion measures, which is a
   * claim about the mechanism rather than about a leak — an implementation
   * that opened one resource and kept it for the request would fail while
   * leaking nothing. After it, what is measured is growth, and growth is what
   * the regression behind this criterion produced: `opendir()` with no
   * matching `closedir()` left one handle per writable wrapper per listing, so
   * every further scan pushed the count up again.
   *
   * **The repeats need managers of their own.** Both memos are per request, so
   * calling twice on one manager reads nothing and would count the same
   * absence three more times. Every one of them is kept alive to the end of
   * the test, the way the container keeps the shared one alive to the end of
   * the request: a handle held by a manager that has already been collected is
   * not the leak this is looking for.
   *
   * The manager and the file system are fetched before the first scan so that
   * a lazily built service cannot be mistaken for a leaked handle.
   */
  public function testRepeatedScansHoldNoMoreStreamResourcesThanTheFirst(): void {
    $this->makeStyleDirectory(self::SCALE_ID);
    $this->makeStyleDirectory(self::CROP_ID);

    $manager = $this->container->get('neo_image.style_manager');
    $this->container->get('file_system');

    $names = $manager->getStyleNames();
    $this->assertCount(2, $names, 'The scan really did read the directories it is being counted around.');

    $baseline = count(get_resources('stream'));

    $managers = [];
    for ($repeat = 1; $repeat <= 3; $repeat++) {
      $managers[] = $manager = $this->freshManager();
      $styles = $manager->getStyles();

      $this->assertCount(
        2,
        $styles,
        sprintf('Scan %d really did read the directories too.', $repeat)
      );
      $this->assertLessThanOrEqual(
        $baseline,
        count(get_resources('stream')),
        sprintf('Scan %d left the process holding no more than the first one did.', $repeat)
      );
    }

    $this->assertCount(3, $managers, 'Three further scans ran, each on a manager of its own.');
  }

  /**
   * Every call in one request answers the same names and the same styles.
   *
   * Acceptance criterion: it answers the same names and the same style
   * instances for every call within one request.
   *
   * The names half is what changed: they were listed afresh on every call, so
   * two callers in one request could disagree about what is on disk. They are
   * memoised now, and a directory created between two calls is what makes that
   * observable — an unmemoised listing would report it.
   *
   * The styles half is identity rather than equality. The same neo style
   * objects coming back from every call is the whole mechanism by which the
   * flush hook's built styles collapse: the manager is a shared service, so one
   * object memoising its **built style** only helps if every caller is handed
   * that same object. A third call follows the second, because a memo written
   * once and overwritten by its own reader passes a two-call test.
   */
  public function testItAnswersTheSameNamesAndStyleInstancesEveryCall(): void {
    $this->makeStyleDirectory(self::SCALE_ID);
    $this->makeStyleDirectory(self::CROP_ID);

    $manager = $this->container->get('neo_image.style_manager');

    $first = $manager->getStyleNames();
    $this->assertSame($first, $manager->getStyleNames(), 'Two calls answer the same names.');

    $this->makeStyleDirectory(self::LATE_ID);

    $this->assertSame(
      $first,
      $manager->getStyleNames(),
      'And so does a third, after the directory listing on disk has changed underneath it.'
    );

    $styles = $manager->getStyles();
    $this->assertSame(array_keys($styles), $first, 'The styles answer to the names the scan listed.');

    foreach ([$manager->getStyles(), $manager->getStyles()] as $again) {
      foreach ($styles as $id => $style) {
        $this->assertInstanceOf(NeoImageStyle::class, $style);
        $this->assertSame($style, $again[$id], sprintf('"%s" is one object for the whole request.', $id));
      }
    }

    $filtered = $manager->getStyles(['s']);
    $this->assertSame(
      $styles[self::SCALE_ID],
      $filtered[self::SCALE_ID],
      'Filtering answers the same objects rather than parsing new ones.'
    );
  }

  /**
   * A directory that is not a neo directory is not this module's business.
   *
   * Acceptance criterion: it still ignores a directory whose name does not
   * begin with the neo prefix.
   *
   * A **regression pin**: the prefix filter is what keeps a configured image
   * style's derivatives out of the manager's answers and out of the admin
   * flush, and swapping the directory read for one that holds no handle is not
   * allowed to move it. Both answers are pinned, because both are filtered from
   * the same listing now.
   */
  public function testItStillIgnoresDirectoriesWithoutTheNeoPrefix(): void {
    $this->makeStyleDirectory(self::SCALE_ID);
    $foreign = $this->makeStyleDirectory('large');
    $this->makeStyleDirectory('neo_image');

    $manager = $this->container->get('neo_image.style_manager');

    $this->assertSame([self::SCALE_ID], $manager->getStyleNames(), 'Only a neo- name is listed.');
    $this->assertSame([self::SCALE_ID], array_keys($manager->getStyles()), 'And only a neo- name becomes a style.');
    $this->assertDirectoryExists($foreign, 'Nothing about the scan touches what it ignores.');
  }

  /**
   * The effect-type filter answers exactly what it answered before.
   *
   * Acceptance criterion: it still filters the styles it answers by the effect
   * types a caller asks for.
   *
   * A **regression pin** for the manager's other parameter. The image settings
   * form asks for `f`, `s` and `e` to label its size select, so this is the
   * filter a person sees; folding two listings into one is not allowed to
   * change what it selects, what it leaves out, or the fact that no argument
   * means everything.
   */
  public function testItStillFiltersTheStylesByEffectType(): void {
    $this->makeStyleDirectory(self::SCALE_ID);
    $this->makeStyleDirectory(self::CROP_ID);
    $this->makeStyleDirectory(self::EXACT_ID);

    $manager = $this->container->get('neo_image.style_manager');

    $all = array_keys($manager->getStyles());
    sort($all);
    $this->assertSame([self::CROP_ID, self::EXACT_ID, self::SCALE_ID], $all, 'No filter is every style.');

    $this->assertSame([self::SCALE_ID], array_keys($manager->getStyles(['s'])));
    $this->assertSame([self::CROP_ID], array_keys($manager->getStyles(['c'])));

    $pair = array_keys($manager->getStyles(['e', 's']));
    sort($pair);
    $this->assertSame([self::EXACT_ID, self::SCALE_ID], $pair, 'Several types are a union.');

    $this->assertSame([], array_keys($manager->getStyles(['fw'])), 'A type nothing on disk carries is nothing.');
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
