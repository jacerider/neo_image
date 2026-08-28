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
   * It accepts the id every setter produces and parses it back unchanged.
   *
   * Acceptance criterion: *it accepts the id every setter produces, and parses
   * it back to the parameters it was built from.*
   *
   * This is the safety argument for the whole **id grammar**, and it is why the
   * row starts at a setter rather than at a hand-written id: a grammar derived
   * from the setters can only be wrong by rejecting something the module itself
   * emits, and that is exactly what this asserts cannot happen. Every one of
   * the eight setters is driven, plus `auto()`, which picks one of three of
   * them, plus the two- and three-effect combinations that reach the `~`
   * separator.
   *
   * Three assertions per row close the loop: the setters build the parameters
   * the row names, those parameters serialise to the id, and that id parses
   * back to the *same* parameters — integers included, which is what makes
   * serialise-then-parse an identity rather than a near miss.
   *
   * @param string $description
   *   What the row covers, so a failure names the setter rather than a row
   *   index.
   * @param array $options
   *   Constructor options, which the constructor dispatches to the setters by
   *   name.
   * @param string $expectedId
   *   The id those parameters serialise to.
   * @param array $expectedParams
   *   The parameters the setters built, which the id must parse back to.
   */
  #[DataProvider('providerSetterProducedIds')]
  public function testAcceptsTheIdEverySetterProduces(string $description, array $options, string $expectedId, array $expectedParams): void {
    $style = new NeoImageStyle($options);

    $this->assertSame($expectedParams, $style->getParameters(), $description . ': the setters build these parameters');
    $this->assertSame($expectedId, $style->getImageStyleName(), $description . ': which serialise to its id');
    $this->assertSame($expectedParams, $style->convertIdToParams($expectedId), $description . ': and that id parses back to them');
  }

  /**
   * It refuses an unknown key, a missing value and a value outside its rules.
   *
   * Acceptance criterion: *it refuses an unknown effect key, an unknown
   * property key, a property with no value, and a value outside its
   * vocabulary.*
   *
   * A **rejected id** is a first-class outcome rather than a degraded style.
   * Each of these built something today: an unknown effect key was dropped and
   * answered a webp-only no-op, an unknown property key built an effect with an
   * empty-string key, and `neo-s--w` — the sharp one, pinned as a defect by the
   * characterisation tests this ticket replaces — parsed to a NULL width and
   * named itself `neo-s--w-`, a **derivative directory** nobody could ask for
   * again.
   *
   * Values are only checked where the codec owns the vocabulary. A width or a
   * height must be the digits a setter's integer cast produces, and an anchor
   * must be one of the nine short keys every anchor-taking setter already
   * throws on. A background is checked for nothing but being non-empty and
   * inside the id's own alphabet: a colour is the caller's value and reaches
   * the codec from a Twig template, so a colour typo answering 404 would be a
   * worse failure than the wrong background it produces today.
   *
   * @param string $description
   *   What the row covers.
   * @param string $id
   *   The id the codec must refuse.
   */
  #[DataProvider('providerIdsOutsideTheVocabulary')]
  public function testRefusesUnknownKeysMissingValuesAndValuesOutsideTheVocabulary(string $description, string $id): void {
    $this->expectException(\InvalidArgumentException::class);

    (new NeoImageStyle())->convertIdToParams($id);
  }

  /**
   * Ids whose keys or values are not in the grammar.
   *
   * @return \Generator
   *   Rows of description and the id to refuse.
   */
  public static function providerIdsOutsideTheVocabulary(): \Generator {
    yield 'unknown effect key' => ['an effect key the codec does not declare', 'neo-x--w-1'];
    yield 'unknown effect key alone' => ['an unknown effect key carrying no properties', 'neo-x'];
    yield 'unknown effect key in a combination' => ['an unknown effect key beside a known one', 'neo-s--w-300~x--w-1'];
    yield 'unknown property key' => ['a property key the codec does not declare', 'neo-s--z-1'];
    yield 'property not allowed on this effect' => ['a known property the effect does not allow', 'neo-fw--w-300_h-200'];
    yield 'property on an effect that allows none' => [
      'a property on the one effect that carries none',
      'neo-cs--w-300',
    ];
    yield 'anchor on an effect that does not take one' => [
      'an anchor on an effect with no anchor',
      'neo-f--w-300_h-200_a-lt',
    ];
    yield 'background outside exact' => [
      'a background on anything but exact',
      'neo-sc--w-300_h-200_bg-ffffff',
    ];
    yield 'property with no value' => ['a property named with no value at all', 'neo-s--w'];
    yield 'property with an empty value' => ['a property whose value is the empty string', 'neo-s--w-'];
    yield 'value with no property' => ['a value with no property key in front of it', 'neo-s---300'];
    yield 'empty property list' => ['a separator with no properties behind it', 'neo-s--'];
    yield 'non-numeric width' => ['a width that is not digits', 'neo-s--w-abc'];
    yield 'signed width' => ['a signed width', 'neo-s--w--300'];
    yield 'decimal height' => ['a height that is not a whole number', 'neo-r--w-300_h-200.5'];
    yield 'padded width' => ['a width that is not the digits an integer cast answers', 'neo-s--w-0300'];
    yield 'unknown anchor' => ['an anchor outside the nine short keys', 'neo-c--w-300_h-200_a-zz'];
    yield 'long anchor name' => ['an anchor given by its long name', 'neo-c--w-300_h-200_a-lefttop'];
    yield 'empty background' => ['a background with no value', 'neo-e--w-1200_h-630_bg-'];
    yield 'background outside the id alphabet' => [
      'a background carrying a character the id cannot hold',
      'neo-e--w-1200_h-630_bg-ff.ff',
    ];
  }

  /**
   * It refuses a repeat, a missing requirement and a missing prefix.
   *
   * Acceptance criterion: *it refuses a repeated effect, a repeated property,
   * an effect missing a property it requires, and an id without the neo
   * prefix.*
   *
   * These are the rules that make the two halves of the **codec** inverses of
   * each other rather than merely compatible. A repeat cannot survive a round
   * trip at all — the parse keys parameters by effect and by property, so the
   * second `s` or the second `w` silently wins and the id that comes back out
   * is a different string from the one that went in. A requirement is read
   * straight off the setters: `neo-r` and `neo-s` are ids no setter can
   * produce, because resize sets both dimensions and scale refuses to set
   * neither.
   *
   * The prefix is the module's whole claim on a style name. Without it the
   * string belongs to a configured image style and is none of this codec's
   * business.
   *
   * @param string $description
   *   What the row covers.
   * @param string $id
   *   The id the codec must refuse.
   */
  #[DataProvider('providerIdsThatBreakTheEffectRules')]
  public function testRefusesRepeatsMissingRequirementsAndMissingPrefixes(string $description, string $id): void {
    $this->expectException(\InvalidArgumentException::class);

    (new NeoImageStyle())->convertIdToParams($id);
  }

  /**
   * Ids whose shape the effect rules forbid.
   *
   * @return \Generator
   *   Rows of description and the id to refuse.
   */
  public static function providerIdsThatBreakTheEffectRules(): \Generator {
    yield 'repeated effect' => ['the same effect twice', 'neo-s--w-300~s--w-400'];
    yield 'repeated effect with identical properties' => ['the same effect twice with the same properties', 'neo-cs~cs'];
    yield 'repeated effect apart' => ['the same effect twice with another between them', 'neo-s--w-300~cs~s--w-400'];
    yield 'repeated property' => ['the same property twice on one effect', 'neo-s--w-300_w-400'];
    yield 'repeated property with the same value' => [
      'the same property twice with the same value',
      'neo-r--w-300_h-200_h-200',
    ];
    yield 'resize missing a height' => ['resize, which needs both dimensions', 'neo-r--w-300'];
    yield 'resize missing a width' => ['resize, which needs both dimensions', 'neo-r--h-200'];
    yield 'resize with no properties' => ['resize with nothing at all', 'neo-r'];
    yield 'scale with neither dimension' => ['scale, which needs at least one dimension', 'neo-s'];
    yield 'crop missing a height' => ['crop, which needs both dimensions', 'neo-c--w-300_h-200~c--w-300'];
    yield 'crop with only an anchor' => ['crop with an anchor and no dimensions', 'neo-c--a-lt'];
    yield 'scale and crop missing a dimension' => ['scale and crop, which needs both dimensions', 'neo-sc--w-300_a-c'];
    yield 'focal missing a dimension' => ['focal scale and crop, which needs both dimensions', 'neo-f--w-300'];
    yield 'focal by width missing its width' => ['focal crop by width with no width', 'neo-fw'];
    yield 'exact missing a height' => ['exact, which needs both dimensions', 'neo-e--w-1200_bg-ffffff'];
    yield 'exact with only a background' => ['exact with a background and no dimensions', 'neo-e--bg-ffffff'];
    yield 'no prefix' => ['a bare effect with no prefix', 's--w-300'];
    yield 'wrong prefix' => ['another prefix entirely', 'foo-s--w-300'];
    yield 'configured style name' => ['a configured image style name', 'large'];
    yield 'prefix without a hyphen' => ['the prefix without its hyphen', 'neos--w-300'];
    yield 'prefix alone' => ['the prefix and nothing else', 'neo-'];
    yield 'empty id' => ['the empty string', ''];
    yield 'trailing separator' => ['a separator with no effect behind it', 'neo-cs~'];
    yield 'leading separator' => ['a separator with no effect in front of it', 'neo-~cs'];
  }

  /**
   * It answers integers, and refuses what a typed getter could not hold.
   *
   * Acceptance criterion: *it answers integers for a parsed width and height,
   * and never lets a non-numeric value reach a getter.*
   *
   * The parse is the only route from a **style id** to `getWidth()` and
   * `getHeight()`, which declare `int|null` in a class with no
   * `strict_types` — so a numeric string was silently coerced and a
   * non-numeric one was a `TypeError` reachable from an admin form. Both
   * halves close here: validation has already proved the value is digits, so
   * the cast is free, and anything that is not digits never gets past the
   * parse to be cast at all.
   */
  public function testAnswersIntegersForParsedWidthsAndHeights(): void {
    $codec = new NeoImageStyle();

    $params = $codec->convertIdToParams('neo-r--w-300_h-200~s--w-800');
    $this->assertSame(['r' => ['w' => 300, 'h' => 200], 's' => ['w' => 800]], $params, 'a parsed width and height are integers');

    $style = (new NeoImageStyle())->setParameters($params);
    $this->assertSame(300, $style->getWidth(), 'the width getter answers its declared int with nothing to coerce');
    $this->assertSame(200, $style->getHeight(), 'the height getter answers its declared int with nothing to coerce');

    $this->expectException(\InvalidArgumentException::class);
    $codec->convertIdToParams('neo-s--w-zz');
  }

  /**
   * It serialises anything, including parameters no id could be parsed into.
   *
   * Acceptance criterion: *it still serialises without refusing anything, for
   * any parameters it is given.*
   *
   * The two halves of the **codec** are deliberately asymmetric. The parse
   * faces untrusted input — a URL, a directory name — so it refuses. The
   * serialise half runs on every render, from `getImageStyleName()`, so a
   * throw there would turn a bad parameter into a white screen instead of a
   * wrong image.
   *
   * Every row is a parameter set the **id grammar** refuses, which is what
   * makes the asymmetry visible: the same string is produced without complaint
   * and rejected on the way back in. That a caller can still build a
   * background carrying a space and get an id the codec would refuse is a
   * producer bug and a known one; it is not the parse's to fix.
   *
   * @param string $description
   *   What the row covers.
   * @param array $params
   *   The parameters to serialise.
   * @param string $expectedId
   *   The id they serialise to.
   */
  #[DataProvider('providerParametersOutsideTheGrammar')]
  public function testStillSerialisesWithoutRefusingAnything(string $description, array $params, string $expectedId): void {
    $codec = new NeoImageStyle();

    $this->assertSame($expectedId, $codec->convertParamsToId($params), $description . ': serialises without refusing');

    $this->expectException(\InvalidArgumentException::class);
    $codec->convertIdToParams($expectedId);
  }

  /**
   * Parameter sets the grammar refuses but the producer still serialises.
   *
   * @return \Generator
   *   Rows of description, parameters and the id they serialise to.
   */
  public static function providerParametersOutsideTheGrammar(): \Generator {
    yield 'no parameters' => ['a style with no effects at all', [], 'neo-'];
    yield 'unknown effect' => ['an effect key the grammar does not declare', ['x' => ['w' => 1]], 'neo-x--w-1'];
    yield 'unknown property' => ['a property key the grammar does not declare', ['s' => ['z' => 1]], 'neo-s--z-1'];
    yield 'empty configuration' => ['an effect whose configuration is an empty array', ['s' => []], 'neo-s--'];
    yield 'missing requirement' => ['an effect missing a property it requires', ['r' => ['w' => 300]], 'neo-r--w-300'];
    yield 'non-numeric width' => ['a width that is not digits', ['s' => ['w' => 'zz']], 'neo-s--w-zz'];
    yield 'unknown anchor' => [
      'an anchor outside the nine short keys',
      ['c' => ['w' => 300, 'h' => 200, 'a' => 'zz']],
      'neo-c--w-300_h-200_a-zz',
    ];
    yield 'background with a space' => [
      'a background the producer accepts and the parse cannot',
      ['e' => ['w' => 1200, 'h' => 630, 'bg' => 'ff ff']],
      'neo-e--w-1200_h-630_bg-ff ff',
    ];
    yield 'sentinel beside junk' => [
      'the no-property sentinel beside an unknown effect',
      ['cs' => 1, 'x' => 1],
      'neo-cs~x',
    ];
  }

  /**
   * Every setter the class exposes, with the id and parameters it produces.
   *
   * @return \Generator
   *   Rows of description, constructor options, expected id and expected
   *   parameters.
   */
  public static function providerSetterProducedIds(): \Generator {
    yield 'resize' => [
      'resize (r)',
      ['size' => [300, 200]],
      'neo-r--w-300_h-200',
      ['r' => ['w' => 300, 'h' => 200]],
    ];
    yield 'scale by width' => [
      'scale by width (s)',
      ['scale' => [300]],
      'neo-s--w-300',
      ['s' => ['w' => 300]],
    ];
    yield 'scale by height' => [
      'scale by height (s)',
      ['scale' => [NULL, 200]],
      'neo-s--h-200',
      ['s' => ['h' => 200]],
    ];
    yield 'scale by both' => [
      'scale by width and height (s)',
      ['scale' => [300, 200]],
      'neo-s--w-300_h-200',
      ['s' => ['w' => 300, 'h' => 200]],
    ];
    yield 'crop' => [
      'crop (c)',
      ['crop' => [300, 200, 'left-top']],
      'neo-c--w-300_h-200_a-lt',
      ['c' => ['w' => 300, 'h' => 200, 'a' => 'lt']],
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
      ['sc' => ['w' => 300, 'h' => 200, 'a' => 'c']],
    ];
    yield 'focal' => [
      'focal scale and crop (f)',
      ['focal' => [300, 200]],
      'neo-f--w-300_h-200',
      ['f' => ['w' => 300, 'h' => 200]],
    ];
    yield 'focal by width' => [
      'focal crop by width (fw)',
      ['focalWidth' => [300]],
      'neo-fw--w-300',
      ['fw' => ['w' => 300]],
    ];
    yield 'exact with a background' => [
      'exact (e) with a background and no anchor',
      ['exact' => [1200, 630, NULL, '#ffffff']],
      'neo-e--w-1200_h-630_bg-ffffff',
      ['e' => ['w' => 1200, 'h' => 630, 'bg' => 'ffffff']],
    ];
    yield 'exact with an anchor and a background' => [
      'exact (e) with an anchor and a background',
      ['exact' => [1200, 630, 'right-bottom', 'ffffff']],
      'neo-e--w-1200_h-630_a-rb_bg-ffffff',
      ['e' => ['w' => 1200, 'h' => 630, 'a' => 'rb', 'bg' => 'ffffff']],
    ];
    yield 'auto with both dimensions' => [
      'auto (f) picks a focal crop when both dimensions are given',
      ['auto' => [300, 200]],
      'neo-f--w-300_h-200',
      ['f' => ['w' => 300, 'h' => 200]],
    ];
    yield 'auto with one dimension' => [
      'auto (s) picks a scale when only one dimension is given',
      ['auto' => [300]],
      'neo-s--w-300',
      ['s' => ['w' => 300]],
    ];
    yield 'auto exact' => [
      'auto (e) picks an exact size when asked for one',
      ['auto' => [1200, 630, TRUE, '#000000']],
      'neo-e--w-1200_h-630_bg-000000',
      ['e' => ['w' => 1200, 'h' => 630, 'bg' => '000000']],
    ];
    yield 'two effects' => [
      'two effects joined by the separator',
      ['cropSides' => [], 'focal' => [300, 200]],
      'neo-cs~f--w-300_h-200',
      ['cs' => 1, 'f' => ['w' => 300, 'h' => 200]],
    ];
    yield 'three effects' => [
      'three effects joined by the separator',
      ['cropSides' => [], 'scale' => [800], 'exact' => [400, 400, 'center-center', '000000']],
      'neo-cs~s--w-800~e--w-400_h-400_a-c_bg-000000',
      ['cs' => 1, 's' => ['w' => 800], 'e' => ['w' => 400, 'h' => 400, 'a' => 'c', 'bg' => '000000']],
    ];
  }

  /**
   * It parses and re-serialises every id in the grammar to the same string.
   *
   * Acceptance criterion: *it parses and re-serialises every id in the grammar
   * to the same string, across single and combined effects.*
   *
   * **Round-trip safety** is the whole deliverable, and this is the direction a
   * request travels: a **style id** arrives in a URL, is parsed into
   * parameters, and the style built from those parameters names the
   * **derivative directory** by serialising them again. When those two strings
   * differ, the derivative is written where nobody will ask for it and PHP
   * regenerates the image on every request for the life of the URL.
   *
   * The enumeration is read off the **id grammar** itself rather than written
   * out by hand, so it is exhaustive by construction: every effect the class
   * declares, every legal combination of the properties that effect allows, and
   * every value in a closed vocabulary — which means all nine anchors. A ninth
   * effect added to the declaration is covered here the day it is added, which
   * is what makes "adding an effect is one edit" true rather than aspirational.
   *
   * @param string $id
   *   A well-formed id the grammar admits.
   */
  #[DataProvider('providerGrammarIds')]
  public function testParsesAndReserialisesEveryIdInTheGrammar(string $id): void {
    $codec = new NeoImageStyle();

    $this->assertSame($id, $codec->convertParamsToId($codec->convertIdToParams($id)), $id . ' round-trips through the codec');
  }

  /**
   * Every id the grammar admits, single and combined.
   *
   * Singles are every legal segment of every declared effect. Combinations are
   * every ordered pair of two distinct effects plus a rotating triple, because
   * the `~` separator is only reachable with more than one effect and its
   * ordering is what the parse has to preserve.
   *
   * @return \Generator
   *   Rows of one well-formed id.
   */
  public static function providerGrammarIds(): \Generator {
    $segments = self::grammarSegments();
    foreach ($segments as $list) {
      foreach ($list as $segment) {
        yield 'single ' . $segment => ['neo-' . $segment];
      }
    }
    $first = array_map(static fn (array $list): string => reset($list), $segments);
    foreach ($first as $a => $segmentA) {
      foreach ($first as $b => $segmentB) {
        if ($a === $b) {
          continue;
        }
        yield 'pair ' . $a . '~' . $b => ['neo-' . $segmentA . '~' . $segmentB];
      }
    }
    $keys = array_keys($first);
    foreach ($keys as $index => $a) {
      $b = $keys[($index + 1) % count($keys)];
      $c = $keys[($index + 2) % count($keys)];
      yield 'triple ' . $a . '~' . $b . '~' . $c => ['neo-' . $first[$a] . '~' . $first[$b] . '~' . $first[$c]];
    }
  }

  /**
   * The grammar the codec declares, read straight off the class.
   *
   * @return array
   *   The effect declarations and the property declarations.
   */
  private static function grammar(): array {
    $defaults = (new \ReflectionClass(NeoImageStyle::class))->getDefaultProperties();
    if (!isset($defaults['effects'], $defaults['properties'])) {
      throw new \RuntimeException('NeoImageStyle declares no id grammar: expected an $effects and a $properties declaration to enumerate.');
    }
    return [$defaults['effects'], $defaults['properties']];
  }

  /**
   * Every legal single-effect segment, keyed by effect.
   *
   * @return array
   *   Lists of segment strings, keyed by effect key.
   */
  private static function grammarSegments(): array {
    [$effects, $properties] = self::grammar();
    $segments = [];
    foreach ($effects as $key => $effect) {
      $segments[$key] = [];
      foreach (self::propertySets($effect) as $set) {
        foreach (self::valueCombinations($set, $properties) as $pairs) {
          $segments[$key][] = $pairs ? $key . '--' . implode('_', $pairs) : $key;
        }
      }
    }
    return $segments;
  }

  /**
   * Every property combination one effect declaration admits.
   *
   * @param array $effect
   *   One effect declaration.
   *
   * @return array
   *   Lists of property keys, in the order the effect allows them.
   */
  private static function propertySets(array $effect): array {
    $allowed = $effect['allowed'];
    $required = $effect['required'];
    $requiredAny = $effect['required_any'] ?? [];
    $optional = array_values(array_diff($allowed, $required, $requiredAny));
    $anySets = $requiredAny ? array_filter(self::subsets($requiredAny)) : [[]];
    $sets = [];
    foreach ($anySets as $any) {
      foreach (self::subsets($optional) as $extra) {
        $sets[] = array_values(array_intersect($allowed, array_merge($required, $any, $extra)));
      }
    }
    return $sets;
  }

  /**
   * The power set of a list, smallest first.
   *
   * @param array $items
   *   The items.
   *
   * @return array
   *   Every subset, each in the order the items were given.
   */
  private static function subsets(array $items): array {
    $subsets = [[]];
    foreach ($items as $item) {
      foreach ($subsets as $subset) {
        $subsets[] = array_merge($subset, [$item]);
      }
    }
    return $subsets;
  }

  /**
   * The cartesian product of one property set's representative values.
   *
   * @param array $set
   *   The property keys.
   * @param array $properties
   *   The property declarations.
   *
   * @return array
   *   Lists of `key-value` pairs.
   */
  private static function valueCombinations(array $set, array $properties): array {
    $rows = [[]];
    foreach ($set as $key) {
      $next = [];
      foreach ($rows as $row) {
        foreach (self::representativeValues($key, $properties[$key]) as $value) {
          $next[] = array_merge($row, [$key . '-' . $value]);
        }
      }
      $rows = $next;
    }
    return $rows;
  }

  /**
   * The values one property is exercised with.
   *
   * A property with a closed value vocabulary is exercised with all of it —
   * which is what makes all nine anchors appear. Everything else gets one
   * representative value, because the number line is not the grammar's
   * business.
   *
   * @param string $key
   *   The property key.
   * @param array $property
   *   The property declaration.
   *
   * @return array
   *   The values.
   */
  private static function representativeValues(string $key, array $property): array {
    if (isset($property['values'])) {
      return array_keys($property['values']);
    }
    if (($property['type'] ?? 'string') === 'integer') {
      return [$key === 'h' ? '200' : '300'];
    }
    return ['ffffff'];
  }

}
