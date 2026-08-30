<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the **settable value** rule the module's own setters apply.
 *
 * The **codec**'s serialise half is total and stays total, so the only place
 * the producer end can hold **round-trip safety** up is the setters: a value
 * they store is a value the **id grammar** can carry, checked against the same
 * declaration the parse half reads. What this file asserts is one property —
 * *no id the module's own setters can build is a **rejected id*** — and the two
 * different answers a failed check gets, which are the class's own convention
 * rather than this suite's invention.
 *
 * A negative width or height **throws**: it is a caller's arithmetic error with
 * no sensible fallback, and the same methods already throw two lines away for a
 * bad anchor. A background outside the id alphabet is **dropped**: a colour
 * reaches this class straight from a template, and the class already argues
 * that fataling a typo is worse than the unpadded image not storing it
 * produces. Both answers reach the same objective, because an unstored value is
 * not in an id either.
 *
 * `setParameters()` is deliberately outside this claim. It is the parse's
 * landing spot, handed output the grammar has already admitted, so it gains no
 * check and `setParameters(['s' => []])` still serialises to a rejected id.
 */
#[Group('neo_image')]
final class SettableValuesTest extends UnitTestCase {

  /**
   * It parses back the id every setter produces, to the parameters it stored.
   *
   * Acceptance criterion: *it parses back the id every setter produces, to the
   * parameters that setter stored.*
   *
   * This is the whole deliverable stated as one assertion, and it is driven
   * from the setter rather than from a hand-written id for the same reason the
   * codec's own round-trip test is: a grammar can only be wrong by refusing
   * something the module itself emits. The rows that matter are the ones a
   * caller can plausibly pass and the codec cannot carry — a colour function, a
   * spaced hex, a lone hash — because before the guard each of those built a
   * name that named a **derivative directory** nothing could ever serve.
   *
   * @param string $description
   *   What the row covers, so a failure names the setter rather than an index.
   * @param string $setter
   *   The setter to call.
   * @param array $arguments
   *   The arguments to call it with.
   */
  #[DataProvider('providerSetterCallsThatStore')]
  public function testParsesBackTheIdEverySetterProduces(string $description, string $setter, array $arguments): void {
    $style = new NeoImageStyle();
    $style->$setter(...$arguments);

    $id = $style->getImageStyleName();

    $this->assertSame(
      $style->getParameters(),
      $style->convertIdToParams($id),
      $description . ': the id "' . $id . '" parses back to the parameters the setter stored'
    );
  }

  /**
   * Every setter, with values it stores something for.
   *
   * @return \Generator
   *   Rows of description, setter name and arguments.
   */
  public static function providerSetterCallsThatStore(): \Generator {
    yield 'size' => ['size()', 'size', [300, 200]];
    yield 'size at zero' => ['size() at zero', 'size', [0, 0]];
    yield 'scale by width' => ['scale() by width', 'scale', [300]];
    yield 'scale by height' => ['scale() by height', 'scale', [NULL, 200]];
    yield 'crop' => ['crop()', 'crop', [300, 200, 'left-top']];
    yield 'crop sides' => ['cropSides()', 'cropSides', []];
    yield 'scale crop' => ['scaleCrop()', 'scaleCrop', [300, 200]];
    yield 'focal' => ['focal()', 'focal', [300, 200]];
    yield 'focal width' => ['focalWidth()', 'focalWidth', [300]];
    yield 'exact' => ['exact()', 'exact', [1200, 630]];
    yield 'exact at zero' => ['exact() at zero', 'exact', [0, 0]];
    yield 'exact with a hashed hex' => ['exact() with a hashed hex', 'exact', [1200, 630, NULL, '#ffffff']];
    yield 'exact with a bare word' => ['exact() with a bare word', 'exact', [1200, 630, NULL, 'red']];
    yield 'exact with a colour function' => [
      'exact() with a colour function',
      'exact',
      [1200, 630, NULL, 'rgb(255,0,0)'],
    ];
    yield 'exact with a lone hash' => ['exact() with a lone hash', 'exact', [1200, 630, NULL, '#']];
    yield 'exact with a spaced hex' => [
      'exact() with a spaced hex',
      'exact',
      [1200, 630, 'center-center', 'ff ffff'],
    ];
    yield 'auto exact with a colour function' => [
      'auto() with a colour function',
      'auto',
      [1200, 630, TRUE, 'rgb(0,0,0)'],
    ];
    yield 'auto with both dimensions' => ['auto() with both dimensions', 'auto', [300, 200]];
    yield 'auto with one dimension' => ['auto() with one dimension', 'auto', [300]];
  }

  /**
   * It refuses a negative width or height from each setter that takes one.
   *
   * Acceptance criterion: *it refuses a negative width or height from each of
   * the seven setters that takes one, named per setter, with the bare-constant
   * message the class already uses.*
   *
   * Driven per setter rather than over one representative, so a failure names
   * the setter that stopped guarding rather than a row index. A negative is the
   * sharp case because the `-` a negative carries is the **id grammar**'s own
   * property separator, so `neo-e--w--300_h-200` is not merely an id the parse
   * refuses, it is an id whose property list means something else.
   *
   * The message is a bare constant. That is the shape of every throw this class
   * already carries from a setter — *Invalid anchor value.*, *Width or height
   * must be set.* — and it is deliberately not the sprintf-with-values shape
   * the parse half uses: the parse is naming an id it was handed, where a
   * setter is answering an argument the caller has just typed.
   *
   * @param string $description
   *   What the row covers, so a failure names the setter.
   * @param string $setter
   *   The setter to call.
   * @param array $arguments
   *   The arguments to call it with, one of them negative.
   */
  #[DataProvider('providerSetterCallsWithNegativeDimensions')]
  public function testRefusesTheNegativeWidthOrHeightEachSetterTakes(string $description, string $setter, array $arguments): void {
    $style = new NeoImageStyle();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid width or height value.');

    $style->$setter(...$arguments);
  }

  /**
   * Every setter that takes a width or a height, given a negative one.
   *
   * @return \Generator
   *   Rows of description, setter name and arguments.
   */
  public static function providerSetterCallsWithNegativeDimensions(): \Generator {
    yield 'size, negative width' => ['size() with a negative width', 'size', [-300, 200]];
    yield 'size, negative height' => ['size() with a negative height', 'size', [300, -200]];
    yield 'scale, negative width' => ['scale() with a negative width', 'scale', [-300]];
    yield 'scale, negative height' => ['scale() with a negative height', 'scale', [NULL, -200]];
    yield 'crop, negative width' => ['crop() with a negative width', 'crop', [-300, 200, 'left-top']];
    yield 'crop, negative height' => ['crop() with a negative height', 'crop', [300, -200, 'left-top']];
    yield 'scale crop, negative width' => ['scaleCrop() with a negative width', 'scaleCrop', [-300, 200]];
    yield 'scale crop, negative height' => ['scaleCrop() with a negative height', 'scaleCrop', [300, -200]];
    yield 'focal, negative width' => ['focal() with a negative width', 'focal', [-300, 200]];
    yield 'focal, negative height' => ['focal() with a negative height', 'focal', [300, -200]];
    yield 'focal width, negative width' => ['focalWidth() with a negative width', 'focalWidth', [-300]];
    yield 'exact, negative width' => ['exact() with a negative width', 'exact', [-1200, 630]];
    yield 'exact, negative height' => ['exact() with a negative height', 'exact', [1200, -630]];
  }

  /**
   * It still stores a zero in the six setters that can hold one.
   *
   * Acceptance criterion: *it still stores a zero in the six setters that can
   * hold one — `size()`, `crop()`, `scaleCrop()`, `focal()`, `focalWidth()` and
   * `exact()` — leaving `scale()` and `auto()`'s existing "width or height must
   * be set" refusal untouched.*
   *
   * **This guard is green before the change and green after**, which is what it
   * is for. The **id grammar** admits a zero and round-trips it, so the check
   * added beside it implements the grammar rather than an opinion about sizes,
   * and the tempting simplification — refusing anything falsy — would take a
   * legal dimension away from six setters across the fleet.
   *
   * `scale()` is not in this list and its behaviour is not this ticket's. It
   * stores a dimension only when that dimension is truthy and refuses outright
   * when every one of them is falsy, so it can never hold a zero and
   * `scale(0, 0)` threw before any of this. `auto()` carries the identical
   * refusal and reaches the rest only by delegation. The second half of this
   * method pins that both still answer with their own message rather than the
   * new one, because relaxing those falsy checks to make a zero row pass is the
   * behaviour change this ticket is not allowed to make.
   *
   * @param string $description
   *   What the row covers, so a failure names the setter.
   * @param string $setter
   *   The setter to call.
   * @param array $arguments
   *   The arguments to call it with, at least one of them zero.
   * @param array $expected
   *   The parameters the setter must store.
   */
  #[DataProvider('providerSetterCallsAtZero')]
  public function testStillStoresZeroInTheSixSettersThatCanHoldOne(string $description, string $setter, array $arguments, array $expected): void {
    $style = new NeoImageStyle();
    $style->$setter(...$arguments);

    $this->assertSame($expected, $style->getParameters(), $description . ': stores the zero');
    $this->assertSame(
      $expected,
      $style->convertIdToParams($style->getImageStyleName()),
      $description . ': and the id it builds parses back to it'
    );
  }

  /**
   * The six setters that can store a zero, each given one.
   *
   * @return \Generator
   *   Rows of description, setter name, arguments and expected parameters.
   */
  public static function providerSetterCallsAtZero(): \Generator {
    yield 'size' => ['size() at zero', 'size', [0, 0], ['r' => ['w' => 0, 'h' => 0]]];
    yield 'crop' => ['crop() at zero', 'crop', [0, 0, 'left-top'], ['c' => ['w' => 0, 'h' => 0, 'a' => 'lt']]];
    yield 'scale crop' => ['scaleCrop() at zero', 'scaleCrop', [0, 0], ['sc' => ['w' => 0, 'h' => 0, 'a' => 'c']]];
    yield 'focal' => ['focal() at zero', 'focal', [0, 0], ['f' => ['w' => 0, 'h' => 0]]];
    yield 'focal width' => ['focalWidth() at zero', 'focalWidth', [0], ['fw' => ['w' => 0]]];
    yield 'exact' => ['exact() at zero', 'exact', [0, 0], ['e' => ['w' => 0, 'h' => 0]]];
  }

  /**
   * It leaves `scale()` and `auto()`'s own refusal exactly as it was.
   *
   * The other half of the criterion above: neither method may start answering
   * the settable-value message, because neither ever reaches the guard — they
   * refuse a falsy set of dimensions first, on a rule of their own that this
   * ticket neither owns nor relaxes.
   *
   * @param string $description
   *   What the row covers.
   * @param string $setter
   *   The setter to call.
   * @param array $arguments
   *   The arguments to call it with, every dimension falsy.
   */
  #[DataProvider('providerFalsyDimensionCalls')]
  public function testKeepsTheExistingWidthOrHeightRefusal(string $description, string $setter, array $arguments): void {
    $style = new NeoImageStyle();

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Width or height must be set.');

    $style->$setter(...$arguments);
  }

  /**
   * The calls whose every dimension is falsy.
   *
   * @return \Generator
   *   Rows of description, setter name and arguments.
   */
  public static function providerFalsyDimensionCalls(): \Generator {
    yield 'scale at zero' => ['scale() with a zero width', 'scale', [0]];
    yield 'scale at zero and zero' => ['scale() with a zero width and height', 'scale', [0, 0]];
    yield 'scale with nothing' => ['scale() with neither dimension', 'scale', []];
    yield 'auto at zero and zero' => ['auto() with a zero width and height', 'auto', [0, 0]];
    yield 'auto with nothing' => ['auto() with neither dimension', 'auto', []];
  }

  /**
   * It stores no background the id alphabet cannot carry.
   *
   * Acceptance criterion: *the exact setter stores no background when the value
   * carries a character outside the id alphabet, or is empty once its leading
   * hash is stripped, and the id it then builds parses.*
   *
   * Asserted as *the image loses its pad*, not as *the call fails*. A colour
   * arrives from a template, and the property declaration on the class already
   * argues that answering a colour typo with a fatal is worse than the wrong
   * background it produces instead — so the one value in this grammar that a
   * saved site config can already hold is dropped rather than thrown for. The
   * objective is reached either way, because a value that was never stored is
   * not in an id either, and only the throw would have added a new way for a
   * supported call to white-screen a page.
   *
   * A lone `'#'` is in this list because it is the case that reached an id
   * before: it stripped to the empty string, which the grammar refuses, and it
   * was stored anyway — producing `neo-e--w-1200_h-630_bg-`, a name whose
   * property list gives `bg` no value at all.
   *
   * @param string $description
   *   What the row covers, so a failure names the value.
   * @param string $bg
   *   The background the caller passes.
   */
  #[DataProvider('providerBackgroundsOutsideTheIdAlphabet')]
  public function testStoresNoBackgroundOutsideTheIdAlphabet(string $description, string $bg): void {
    $style = new NeoImageStyle();
    $style->exact(1200, 630, NULL, $bg);

    $this->assertNull($style->getBg(), $description . ': is not stored');
    $this->assertSame(['e' => ['w' => 1200, 'h' => 630]], $style->getParameters(), $description . ': and the size still is');
    $this->assertSame(
      'neo-e--w-1200_h-630',
      $style->getImageStyleName(),
      $description . ': so the id carries no background'
    );
    $this->assertSame(
      $style->getParameters(),
      $style->convertIdToParams($style->getImageStyleName()),
      $description . ': and that id parses'
    );
  }

  /**
   * Backgrounds the **id grammar** cannot carry.
   *
   * @return \Generator
   *   Rows of description and background value.
   */
  public static function providerBackgroundsOutsideTheIdAlphabet(): \Generator {
    yield 'a colour function' => ['a colour function', 'rgb(255,0,0)'];
    yield 'a spaced hex' => ['a spaced hex', 'ff ffff'];
    yield 'a hyphenated hex' => ['a hyphenated hex, whose hyphen is the grammar separator', '#ff-ff-ff'];
    yield 'an underscored hex' => ['an underscored hex, whose underscore joins properties', 'ff_ffff'];
    yield 'a tilde' => ['a value carrying the effect separator', 'ffffff~cs'];
    yield 'a lone hash' => ['a lone hash, empty once stripped', '#'];
    yield 'several hashes' => ['nothing but hashes, empty once stripped', '###'];
  }

  /**
   * It still stores every background the grammar can carry.
   *
   * Acceptance criterion: *the exact setter still stores a hashed hex, a bare
   * hex and a bare alphanumeric word.*
   *
   * **Green before the change and green after.** The rule the setter now
   * applies is only what the id must be able to carry, and a background is
   * deliberately **not** validated as a colour — `'red'` is inside the alphabet
   * and is none of the setter's business. This is the guard against the
   * over-reach the drop above invites: a hex pattern here would refuse a named
   * colour that has worked on every site since the setter existed.
   *
   * @param string $description
   *   What the row covers.
   * @param string $bg
   *   The background the caller passes.
   * @param string $expected
   *   What the setter must store, with any leading hash stripped.
   */
  #[DataProvider('providerBackgroundsInsideTheIdAlphabet')]
  public function testStillStoresTheBackgroundsTheGrammarCanCarry(string $description, string $bg, string $expected): void {
    $style = new NeoImageStyle();
    $style->exact(1200, 630, NULL, $bg);

    $this->assertSame($expected, $style->getBg(), $description . ': is stored, hash stripped');
    $this->assertSame(
      'neo-e--w-1200_h-630_bg-' . $expected,
      $style->getImageStyleName(),
      $description . ': and the id carries it'
    );
    $this->assertSame(
      $style->getParameters(),
      $style->convertIdToParams($style->getImageStyleName()),
      $description . ': and that id parses back to it'
    );
  }

  /**
   * Backgrounds the **id grammar** can carry.
   *
   * @return \Generator
   *   Rows of description, background value and stored value.
   */
  public static function providerBackgroundsInsideTheIdAlphabet(): \Generator {
    yield 'a hashed hex' => ['a hashed hex', '#ffffff', 'ffffff'];
    yield 'a bare hex' => ['a bare hex', 'ffffff', 'ffffff'];
    yield 'an uppercase hashed hex' => ['an uppercase hashed hex', '#FF0000', 'FF0000'];
    yield 'a bare word' => ['a bare word, which is not the setter\'s business to judge', 'red', 'red'];
    yield 'a bare alphanumeric word' => ['a bare alphanumeric word', 'gray50', 'gray50'];
  }

  /**
   * A refused dimension raises what the parse and the anchor guard raise.
   *
   * Acceptance criterion: *a refused dimension raises the same exception class
   * the parse and the anchor guard raise, and no background value raises at
   * all.*
   *
   * One class, so a caller already catching this module's refusals catches this
   * one too and nothing has to learn a second type. The three are compared
   * against each other rather than each asserted against a literal, because the
   * claim is that they agree — the parse half naming an id it was handed, the
   * anchor guard two lines above the new one, and the new one itself.
   */
  public function testRefusedDimensionsRaiseTheSameExceptionClass(): void {
    $parse = NULL;
    try {
      (new NeoImageStyle())->convertIdToParams('neo-s--w-wide');
    }
    catch (\Throwable $e) {
      $parse = $e;
    }

    $anchor = NULL;
    try {
      (new NeoImageStyle())->crop(300, 200, 'nowhere');
    }
    catch (\Throwable $e) {
      $anchor = $e;
    }

    $dimension = NULL;
    try {
      (new NeoImageStyle())->crop(-300, 200, 'left-top');
    }
    catch (\Throwable $e) {
      $dimension = $e;
    }

    $this->assertInstanceOf(\InvalidArgumentException::class, $parse, 'the parse half refuses a value that is not a whole number');
    $this->assertInstanceOf(\InvalidArgumentException::class, $anchor, 'the anchor guard refuses an anchor outside its vocabulary');
    $this->assertInstanceOf(\InvalidArgumentException::class, $dimension, 'and the new guard refuses a negative dimension');
    $this->assertSame($parse::class, $dimension::class, 'the setter raises what the parse raises');
    $this->assertSame($anchor::class, $dimension::class, 'and what the anchor guard beside it raises');
  }

  /**
   * No background value raises, however far outside the alphabet it is.
   *
   * The other half of the criterion above, and the decision that was reversed
   * once: an earlier draft threw here too, which turned a background saved in
   * an older site's image settings into a white screen on every page that used
   * it. The setter answers itself instead, so the call chain the value arrived
   * through carries on and the image renders unpadded.
   *
   * @param string $description
   *   What the row covers.
   * @param string $bg
   *   The background the caller passes.
   */
  #[DataProvider('providerBackgroundsOutsideTheIdAlphabet')]
  public function testNoBackgroundValueRaises(string $description, string $bg): void {
    $style = new NeoImageStyle();

    $this->assertInstanceOf(
      NeoImageStyle::class,
      $style->exact(1200, 630, NULL, $bg),
      $description . ': answers the style rather than raising'
    );
  }

}
