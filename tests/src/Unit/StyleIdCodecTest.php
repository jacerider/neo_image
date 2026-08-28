<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the **style id** **codec** over the vocabulary it declares.
 *
 * The codec is two halves of one string format. `convertParamsToId()`
 * serialises a parameter set into the `neo-` name that appears in a
 * derivative URL and names the **derivative directory**; `convertIdToParams()`
 * parses that name back. Both are pure — no container, no database, no
 * entity — and neither had a test.
 *
 * **This is characterisation, not specification.** Every expectation here
 * states what the codec does *today*, over ids built by the module's own
 * setters, so that the grammar work can change the vocabulary against a net
 * rather than in the dark.
 *
 * Two of today's answers are pinned deliberately even though they are defects:
 *
 * - **A parsed value is a string.** `neo-s--w-300` parses to `'300'`, where
 *   the setter that produced it stored the integer `300`. Serialise-then-parse
 *   is therefore not an identity on the *parameters*, only on the *id*. A
 *   later integer cast on parse closes this, and closing it is a deliberate
 *   edit to the expectations in this provider.
 * - **An effect with no properties parses to the integer `1`**, not to an
 *   empty array, because that is the sentinel `cropSides()` sets. It is the
 *   one effect that legitimately carries no properties.
 *
 * Malformed ids are **not** asserted here. The codec answers them today by
 * emitting PHP warnings and building a degraded style; the refusal that
 * replaces that is the subject of the grammar ticket, and asserting a warning
 * under this site's `failOnWarning` configuration would assert the harness
 * rather than the codec.
 */
#[Group('neo_image')]
final class StyleIdCodecTest extends UnitTestCase {

  /**
   * It serialises and parses every effect the codec declares, as it is today.
   *
   * Every one of the eight effect keys the codec declares is driven through
   * the setter that produces it — `r`, `s`, `c`, `cs`, `sc`, `f`, `fw` and
   * `e` — plus the two- and three-effect combinations that exercise the `~`
   * separator, because the separator is only reachable with more than one
   * effect.
   *
   * Three things are asserted per row: that the parameters serialise to the
   * id, that the id parses to the parameters the codec answers today, and
   * that parsing then serialising answers the same id. The third is
   * **round-trip safety** at the id level, which is the property the
   * derivative directory depends on.
   *
   * @param string $description
   *   What the row covers, so a failure names the effect rather than a row
   *   index.
   * @param array $options
   *   Constructor options, which the constructor dispatches to the setters by
   *   name. Driving the setters rather than hand-writing parameters is what
   *   makes this an assertion about ids the module can actually produce.
   * @param string $expectedId
   *   The id those parameters serialise to.
   * @param array $expectedParams
   *   The parameters that id parses back to, as the codec answers them today.
   */
  #[DataProvider('providerDeclaredEffects')]
  public function testSerialisesAndParsesEveryDeclaredEffect(string $description, array $options, string $expectedId, array $expectedParams): void {
    $style = new NeoImageStyle($options);

    $this->assertSame($expectedId, $style->getImageStyleName(), $description . ': serialises to its id');
    $this->assertSame($expectedParams, $style->convertIdToParams($expectedId), $description . ': parses back to parameters');
    $this->assertSame($expectedId, $style->convertParamsToId($style->convertIdToParams($expectedId)), $description . ': round-trips through the id');
  }

  /**
   * Pins a defect: an id whose value is missing does not round-trip.
   *
   * Not an acceptance criterion — a characterisation of today's answer, so
   * that changing it is a deliberate edit to a named assertion.
   *
   * `neo-s--w` names a property and gives it no value. The parse takes it at
   * face value and answers a NULL width; serialising that answers
   * `neo-s--w-`, which is a *different* string. The request asked for one
   * **derivative directory** and the style that answers it names another, so
   * the static-file shortcut can never hit the derivative and PHP regenerates
   * the image on every request for the life of the URL. The flush hook misses
   * it in the same way, from the other direction.
   *
   * The parse emits an `Undefined array key 1` warning on the way. The warning
   * is part of the defect rather than part of the assertion, and this site's
   * PHPUnit fails a test that triggers one, so it is swallowed for the
   * duration of the call instead of being asserted.
   */
  public function testPinsAnIdWhoseValueIsMissingNotRoundTripping(): void {
    $style = new NeoImageStyle();

    set_error_handler(static fn (): bool => TRUE);
    try {
      $params = $style->convertIdToParams('neo-s--w');
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame(['s' => ['w' => NULL]], $params, 'a property with no value parses to nothing');
    $this->assertSame('neo-s--w-', $style->convertParamsToId($params), 'and serialises to a different id than the one asked for');
  }

  /**
   * Every effect key the codec declares, driven through its own setter.
   *
   * @return \Generator
   *   Rows of description, constructor options, expected id and expected
   *   parsed parameters.
   */
  public static function providerDeclaredEffects(): \Generator {
    yield 'resize' => [
      'resize (r)',
      ['size' => [300, 200]],
      'neo-r--w-300_h-200',
      ['r' => ['w' => '300', 'h' => '200']],
    ];
    yield 'scale by width' => [
      'scale by width (s)',
      ['scale' => [300]],
      'neo-s--w-300',
      ['s' => ['w' => '300']],
    ];
    yield 'scale by height' => [
      'scale by height (s)',
      ['scale' => [NULL, 200]],
      'neo-s--h-200',
      ['s' => ['h' => '200']],
    ];
    yield 'scale by both' => [
      'scale by width and height (s)',
      ['scale' => [300, 200]],
      'neo-s--w-300_h-200',
      ['s' => ['w' => '300', 'h' => '200']],
    ];
    yield 'crop' => [
      'crop (c)',
      ['crop' => [300, 200, 'left-top']],
      'neo-c--w-300_h-200_a-lt',
      ['c' => ['w' => '300', 'h' => '200', 'a' => 'lt']],
    ];
    yield 'crop sides' => [
      'crop sides (cs), the one effect with no properties',
      ['cropSides' => []],
      'neo-cs',
      ['cs' => 1],
    ];
    yield 'scale and crop' => [
      'scale and crop (sc), anchored by default',
      ['scaleCrop' => [300, 200]],
      'neo-sc--w-300_h-200_a-c',
      ['sc' => ['w' => '300', 'h' => '200', 'a' => 'c']],
    ];
    yield 'focal' => [
      'focal scale and crop (f)',
      ['focal' => [300, 200]],
      'neo-f--w-300_h-200',
      ['f' => ['w' => '300', 'h' => '200']],
    ];
    yield 'focal by width' => [
      'focal crop by width (fw)',
      ['focalWidth' => [300]],
      'neo-fw--w-300',
      ['fw' => ['w' => '300']],
    ];
    yield 'exact with a background' => [
      'exact (e) with a background and no anchor',
      ['exact' => [1200, 630, NULL, '#ffffff']],
      'neo-e--w-1200_h-630_bg-ffffff',
      ['e' => ['w' => '1200', 'h' => '630', 'bg' => 'ffffff']],
    ];
    yield 'exact with an anchor and a background' => [
      'exact (e) with an anchor and a background',
      ['exact' => [1200, 630, 'right-bottom', 'ffffff']],
      'neo-e--w-1200_h-630_a-rb_bg-ffffff',
      ['e' => ['w' => '1200', 'h' => '630', 'a' => 'rb', 'bg' => 'ffffff']],
    ];
    yield 'two effects' => [
      'two effects joined by the separator',
      ['cropSides' => [], 'focal' => [300, 200]],
      'neo-cs~f--w-300_h-200',
      ['cs' => 1, 'f' => ['w' => '300', 'h' => '200']],
    ];
    yield 'three effects' => [
      'three effects joined by the separator',
      ['cropSides' => [], 'scale' => [800], 'exact' => [400, 400, 'center-center', '000000']],
      'neo-cs~s--w-800~e--w-400_h-400_a-c_bg-000000',
      ['cs' => 1, 's' => ['w' => '800'], 'e' => ['w' => '400', 'h' => '400', 'a' => 'c', 'bg' => '000000']],
    ];
  }

}
