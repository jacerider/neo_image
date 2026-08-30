<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\NeoImageStyleManager;
use Drupal\neo_image\Settings\ImageSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use Psr\Log\LoggerInterface;

/**
 * Specifies the **style flush** over a list of names.
 *
 * `flushStyle()` asked the stream wrapper manager for the writable wrappers
 * before deleting the one directory it was given, and the image settings
 * form's flush button called it once per name — so deleting twenty-four
 * **derivative directories** rebuilt the wrapper list twenty-four times.
 * `flushStyles()` does the same work against one wrapper list, and
 * `flushStyle()` delegates to it with a single element.
 *
 * This is a pure unit test, and that seam is the reason this is its own
 * ticket. The manager takes `StreamWrapperManagerInterface` and
 * `FileSystemInterface` as constructor arguments, so a mock expecting
 * `getWrappers()` **once** across a flush of many names asserts the claim
 * directly rather than by proxy, and a mocked file system turns "which
 * directories would have been deleted" into an assertable list without
 * deleting anything.
 *
 * The directories themselves are real, under two schemes registered by
 * `FlushTestStream`, because the flush guards every deletion with a
 * `file_exists()` on a `scheme://` uri and a wrapper that resolves nowhere
 * would let a broken flush pass by deleting nothing.
 *
 * It also specifies the **flushable name**: the shape the flush requires of
 * every name a caller hands it before it resolves one into a path, what it
 * refuses — a path escape and a missing prefix, and nothing else — and that
 * the refusal takes the whole list before anything at all is deleted.
 */
#[Group('neo_image')]
final class StyleFlushListTest extends UnitTestCase {

  /**
   * The first writable wrapper's scheme.
   */
  private const SCHEME_ONE = 'neoflushone';

  /**
   * The second writable wrapper's scheme.
   */
  private const SCHEME_TWO = 'neoflushtwo';

  /**
   * The temporary directory holding both wrappers' trees.
   */
  private string $temporaryDirectory;

  /**
   * Every uri handed to the file system's recursive delete, in order.
   *
   * @var string[]
   */
  private array $deleted = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->temporaryDirectory = sys_get_temp_dir() . '/neo-image-flush-' . uniqid();
    // Wrapper one holds both sizes, a directory the **codec** refuses, and a
    // directory that is not this module's at all. Wrapper two holds only one
    // of the sizes, so "every named directory from every wrapper" cannot be
    // satisfied by deleting a fixed number of things.
    $tree = [
      self::SCHEME_ONE => ['neo-s--w-300', 'neo-s--w-600', 'neo-junk', 'large'],
      self::SCHEME_TWO => ['neo-s--w-600', 'neo-junk'],
    ];
    foreach ($tree as $scheme => $directories) {
      foreach ($directories as $directory) {
        mkdir($this->temporaryDirectory . '/' . $scheme . '/styles/' . $directory, 0777, TRUE);
      }
      FlushTestStream::registerScheme($scheme, $this->temporaryDirectory . '/' . $scheme);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    FlushTestStream::unregisterSchemes();
    $this->removeDirectory($this->temporaryDirectory);
    parent::tearDown();
  }

  /**
   * It deletes every named directory from every writable wrapper in one pass.
   *
   * Acceptance criterion: *it deletes every named directory from every
   * writable stream wrapper in one pass.*
   *
   * Two names against two wrappers, where the second wrapper holds only one of
   * them. Three deletions rather than four is what says the list form kept the
   * `file_exists()` guard the single form had, and the uris say each wrapper
   * was visited with the whole list rather than with one name.
   */
  public function testItDeletesEveryNamedDirectoryFromEveryWritableWrapperInOnePass(): void {
    $manager = $this->styleManager($this->wrapperManager($this->once()));

    $manager->flushStyles(['neo-s--w-300', 'neo-s--w-600']);

    $this->assertSame([
      'neoflushone://styles/neo-s--w-300',
      'neoflushone://styles/neo-s--w-600',
      'neoflushtwo://styles/neo-s--w-600',
    ], $this->deleted);
  }

  /**
   * It asks for the writable wrappers exactly once across many names.
   *
   * Acceptance criterion: *it asks for the writable wrappers exactly once for
   * a flush of many names.*
   *
   * This is the whole ticket, asserted at the seam rather than by proxy: the
   * mock manager fails the test on the second `getWrappers()` call, so a flush
   * that has quietly gone back to a loop cannot pass. The deletion count is
   * asserted alongside it so that a flush which asks once because it does
   * nothing does not read as a success.
   */
  public function testItAsksForTheWritableWrappersOnceAcrossManyNames(): void {
    $manager = $this->styleManager($this->wrapperManager($this->once()));

    $manager->flushStyles(['neo-s--w-300', 'neo-s--w-600', 'neo-junk']);

    $this->assertCount(5, $this->deleted);
  }

  /**
   * It still deletes one named directory through the single-name form.
   *
   * Acceptance criterion: *it still deletes a single named directory through
   * the one-name form.*
   *
   * `flushStyle()` is not deprecated and its signature is untouched — it is
   * the convenient call for one name and most callers make it — so what is
   * specified here is that it answers exactly what the list form answers for a
   * one-element list. That is the delegation, stated as behaviour rather than
   * as an implementation detail no caller can see.
   *
   * It *is* narrowed, though, and that is specified separately below: the
   * delegation carries the **flushable name** guard with it, so a name the
   * list form refuses this form refuses too.
   */
  public function testItStillDeletesOneNamedDirectoryThroughTheSingleNameForm(): void {
    $manager = $this->styleManager($this->wrapperManager($this->exactly(2)));

    $manager->flushStyle('neo-s--w-300');
    $throughOneName = $this->deleted;
    $this->deleted = [];
    $manager->flushStyles(['neo-s--w-300']);

    $this->assertSame(['neoflushone://styles/neo-s--w-300'], $throughOneName);
    $this->assertSame($throughOneName, $this->deleted);
  }

  /**
   * It deletes the directory of a name that is not a parseable style.
   *
   * Acceptance criterion: *it deletes a directory whose name is not a
   * parseable style.*
   *
   * The flush takes **names**, not parsed styles, which is what keeps a
   * directory the **codec** refuses removable: `getStyles()` skips it, so a
   * flush over styles would have locked the junk on disk in place. The refusal
   * is asserted first, so this cannot silently become a test about a name the
   * grammar happens to accept.
   */
  public function testItDeletesTheDirectoryOfAnUnparseableName(): void {
    try {
      (new NeoImageStyle())->convertIdToParams('neo-junk');
      $this->fail('Expected the codec to refuse "neo-junk".');
    }
    catch (\InvalidArgumentException) {
      // Refused, as the **id grammar** says it must be.
    }

    $manager = $this->styleManager($this->wrapperManager($this->once()));

    $manager->flushStyles(['neo-junk']);

    $this->assertSame([
      'neoflushone://styles/neo-junk',
      'neoflushtwo://styles/neo-junk',
    ], $this->deleted);
  }

  /**
   * It flushes every neo directory when the form's flush button is submitted.
   *
   * Acceptance criterion: *it flushes every neo directory on disk when the
   * settings form's flush button is submitted.*
   *
   * Two `getWrappers()` calls and no more: one for the **style scan** that
   * produces the names, one for the flush that consumes all of them. The old
   * handler cost one plus one per name. The non-neo directory is left alone,
   * which is the scan's `neo-` filter still doing its job through the handler.
   */
  public function testItFlushesEveryNeoDirectoryWhenTheFormFlushButtonIsSubmitted(): void {
    $manager = $this->styleManager($this->wrapperManager($this->exactly(2)));
    $form = [];

    $this->imageSettings($manager)->flushImageStyles($form, $this->createMock(FormStateInterface::class));

    $deleted = $this->deleted;
    sort($deleted);
    $this->assertSame([
      'neoflushone://styles/neo-junk',
      'neoflushone://styles/neo-s--w-300',
      'neoflushone://styles/neo-s--w-600',
      'neoflushtwo://styles/neo-junk',
      'neoflushtwo://styles/neo-s--w-600',
    ], $deleted);
  }

  /**
   * It refuses a name that escapes the styles directory, and deletes nothing.
   *
   * Acceptance criterion: *it refuses a name that escapes the styles
   * directory, and deletes nothing.*
   *
   * The name carries the `neo-` prefix and a traversal, so it satisfies the
   * mask the **style scan** applies and still resolves to a real directory
   * beside `styles/`. A guard reading the prefix alone lets it through and
   * fails here, which is why a **flushable name** is a single path segment as
   * well as a prefix.
   */
  public function testItRefusesNamesThatEscapeTheStylesDirectory(): void {
    $manager = $this->styleManager($this->wrapperManager($this->never()));

    try {
      $manager->flushStyles(['neo-s--w-300/../../css']);
      $this->fail('Expected the flush to refuse "neo-s--w-300/../../css".');
    }
    catch (\InvalidArgumentException) {
      // Refused, as a name outside the **flushable name** shape must be.
    }

    $this->assertSame([], $this->deleted, 'Nothing was deleted.');
  }

  /**
   * It refuses a name without the `neo-` prefix, and deletes nothing.
   *
   * Acceptance criterion: *it refuses a name that does not carry the `neo-`
   * prefix, and deletes nothing.*
   *
   * `large` is a directory the fixture holds and the **style scan** never
   * lists — a configured image style's, which core's own flush owns. The
   * prefix is what says a directory is this module's to delete, so a name
   * without it is a fault in the caller rather than a directory to remove.
   */
  public function testItRefusesNamesWithoutTheNeoPrefix(): void {
    $manager = $this->styleManager($this->wrapperManager($this->never()));

    try {
      $manager->flushStyles(['large']);
      $this->fail('Expected the flush to refuse "large".');
    }
    catch (\InvalidArgumentException) {
      // Refused, as a name outside the **flushable name** shape must be.
    }

    $this->assertSame([], $this->deleted, 'Nothing was deleted.');
  }

  /**
   * It refuses the whole list before asking for the writable wrappers.
   *
   * Acceptance criterion: *it refuses the whole list before asking the stream
   * wrapper manager for its wrappers.*
   *
   * The first name is legitimate and its directory exists in both wrappers, so
   * a guard applied per name as the loop reaches it deletes two directories and
   * then throws — leaving a caller holding an exception and no way to say which
   * half happened. The mock fails on any `getWrappers()` call, which pins the
   * check ahead of the wrapper list rather than merely ahead of the deletion.
   */
  public function testItRefusesTheWholeListBeforeAskingForTheWritableWrappers(): void {
    $manager = $this->styleManager($this->wrapperManager($this->never()));

    try {
      $manager->flushStyles(['neo-s--w-300', 'neo-junk/../../css']);
      $this->fail('Expected the flush to refuse the list.');
    }
    catch (\InvalidArgumentException) {
      // Refused whole, so the legitimate first name is not deleted either.
    }

    $this->assertSame([], $this->deleted, 'Nothing was deleted.');
  }

  /**
   * It refuses the same names through the single-name form.
   *
   * Acceptance criterion: *it refuses the same names through the single-name
   * form, since `flushStyle()` is what most callers hold.*
   *
   * The guard lives in `flushStyles()` alone and `flushStyle()` inherits it by
   * delegating, which is the point: one condition, no second copy to drift.
   * Both refusals are asserted here because the one-name form is the call
   * every site outside this module makes.
   */
  public function testItRefusesTheSameNamesThroughTheSingleNameForm(): void {
    $manager = $this->styleManager($this->wrapperManager($this->never()));

    foreach (['neo-s--w-300/../../css', 'large'] as $refused) {
      try {
        $manager->flushStyle($refused);
        $this->fail(sprintf('Expected the flush to refuse "%s".', $refused));
      }
      catch (\InvalidArgumentException) {
        // Refused through the one-name form as through the list form.
      }
    }

    $this->assertSame([], $this->deleted, 'Nothing was deleted.');
  }

  /**
   * It refuses with an exception naming the offending name.
   *
   * Acceptance criterion: *the refusal is an `\InvalidArgumentException` whose
   * message names the offending name.*
   *
   * The type is the **codec**'s, so a caller has one kind of refusal to catch
   * from this module's names rather than two. The reader of the message is a
   * developer whose call site is wrong, and the list may be long, so the
   * message has to say which name in it was the problem — the offending one,
   * not the legitimate name standing in front of it.
   */
  public function testTheRefusalNamesTheOffendingName(): void {
    $manager = $this->styleManager($this->wrapperManager($this->never()));

    try {
      $manager->flushStyles(['neo-s--w-300', 'neo-s--w-600/../../js']);
      $this->fail('Expected the flush to refuse "neo-s--w-600/../../js".');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('neo-s--w-600/../../js', $e->getMessage());
    }
  }

  /**
   * It accepts a scan-shaped name carrying `~` and `_`, and deletes it.
   *
   * Acceptance criterion: *a scan-shaped name carrying `~` and `_` — the shape
   * most real **derivative directories** have — is accepted and its directory
   * deleted.*
   *
   * The four fixture names above are all plain single segments, and a real
   * derivative directory usually is not: the **crop** and **aspect** parameters
   * put `~` and `_` in the majority of the names a populated site holds. A
   * guard tightened to one plain segment reads like "prefix and no traversal",
   * satisfies every other criterion here, and then refuses the image settings
   * form's own flush button for nearly every directory on disk. What a
   * **flushable name** refuses is a path escape and a missing prefix, and
   * nothing else — which is what this pins. The directory is created here
   * rather than in `setUp()` so that no existing assertion moves.
   */
  public function testItAcceptsScanShapedNamesCarryingTildeAndUnderscore(): void {
    $name = 'neo-cs~e--w-36_h-36';
    mkdir($this->temporaryDirectory . '/' . self::SCHEME_ONE . '/styles/' . $name, 0777, TRUE);

    $manager = $this->styleManager($this->wrapperManager($this->once()));

    $manager->flushStyles([$name]);

    $this->assertSame(['neoflushone://styles/' . $name], $this->deleted);
  }

  /**
   * Builds a stream wrapper manager over the two registered schemes.
   *
   * @param \PHPUnit\Framework\MockObject\Rule\InvocationOrder $expected
   *   How many times the flush under test may ask for the wrappers.
   *
   * @return \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   *   The mocked manager.
   */
  private function wrapperManager(InvocationOrder $expected): StreamWrapperManagerInterface {
    $wrapperManager = $this->createMock(StreamWrapperManagerInterface::class);
    $wrapperManager->expects($expected)
      ->method('getWrappers')
      ->with(StreamWrapperInterface::WRITE_VISIBLE)
      ->willReturn([
        self::SCHEME_ONE => ['type' => StreamWrapperInterface::WRITE_VISIBLE],
        self::SCHEME_TWO => ['type' => StreamWrapperInterface::WRITE_VISIBLE],
      ]);
    return $wrapperManager;
  }

  /**
   * Builds the style manager over a wrapper mock and a recording file system.
   *
   * @param \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface $wrapperManager
   *   The stream wrapper manager the flush asks for its wrappers.
   *
   * @return \Drupal\neo_image\NeoImageStyleManager
   *   The style manager under test.
   */
  private function styleManager(StreamWrapperManagerInterface $wrapperManager): NeoImageStyleManager {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('deleteRecursive')->willReturnCallback(function (string $path): bool {
      $this->deleted[] = $path;
      return TRUE;
    });
    return new NeoImageStyleManager($wrapperManager, $fileSystem, $this->createMock(LoggerInterface::class));
  }

  /**
   * Builds the image settings plugin over a style manager.
   *
   * @param \Drupal\neo_image\NeoImageStyleManager $styleManager
   *   The style manager the flush handler drives.
   *
   * @return \Drupal\neo_image\Settings\ImageSettings
   *   The settings plugin, constructed exactly as `create()` constructs it.
   */
  private function imageSettings(NeoImageStyleManager $styleManager): ImageSettings {
    return new ImageSettings(
      ['config' => [], 'variation' => [], 'variation_id' => NULL],
      'neo_image',
      ['configuration' => []],
      $this->createMock(MessengerInterface::class),
      $this->createMock(FormBuilderInterface::class),
      $styleManager,
    );
  }

  /**
   * Removes a directory tree the test created.
   *
   * @param string $directory
   *   The directory to remove.
   */
  private function removeDirectory(string $directory): void {
    if (!is_dir($directory)) {
      return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $directory . '/' . $entry;
      is_dir($path) ? $this->removeDirectory($path) : unlink($path);
    }
    rmdir($directory);
  }

}
