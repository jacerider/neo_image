<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the accessors and the predicate a style answers on its own.
 *
 * `label()`, `getWidth()`, `getHeight()` and `isExternalUri()` need no
 * container, no database and no entity: the first three read the parameter
 * array a setter filled, and the fourth is static string handling. None of
 * them had a test.
 *
 * These were written as characterisation, and two of their expectations were
 * pinned as defects for the **id grammar** work to change. It has: the parse
 * now answers integers, so the getter that declares `int` is handed one, and
 * `label()` no longer iterates the no-property sentinel `cropSides()` sets, so
 * the effect that legitimately carries no properties can be labelled without a
 * `foreach() argument must be of type array|object` warning behind it.
 */
#[Group('neo_image')]
final class StyleAccessorsTest extends UnitTestCase {

  /**
   * It labels a style from its effect, property and anchor vocabulary.
   *
   * The label is what the image-size select on the settings form shows an
   * editor, so it reads all three vocabularies at once: the effect label, the
   * long name of each property key, and — for an anchor — the long name of
   * the value. All nine anchors are enumerated, because the anchor is the one
   * property whose value is translated rather than printed.
   *
   * @param string $description
   *   What the row covers.
   * @param array $options
   *   Constructor options, dispatched to the setters by name.
   * @param string $expected
   *   The label the style answers today.
   */
  #[DataProvider('providerLabels')]
  public function testLabelsStyleFromItsVocabulary(string $description, array $options, string $expected): void {
    $this->assertSame($expected, (new NeoImageStyle($options))->label(), $description);
  }

  /**
   * The label vocabulary: effects, properties and all nine anchors.
   *
   * @return \Generator
   *   Rows of description, constructor options and expected label.
   */
  public static function providerLabels(): \Generator {
    yield 'resize' => [
      'resize prints both dimensions',
      ['size' => [300, 200]],
      'Resize (width: 300 | height: 200)',
    ];
    yield 'scale by width' => [
      'scale prints only the property it was given',
      ['scale' => [300]],
      'Scale (width: 300)',
    ];
    yield 'focal by width' => [
      'focal crop by width',
      ['focalWidth' => [300]],
      'Focal Scale by Width (width: 300)',
    ];
    yield 'exact with a background' => [
      'a background prints as its raw value',
      ['exact' => [1200, 630, NULL, '#ffffff']],
      'Exact (width: 1200 | height: 630 | background: ffffff)',
    ];
    yield 'crop sides' => [
      'the one effect with no properties labels itself alone',
      ['cropSides' => []],
      'Crop Sides',
    ];
    yield 'two effects' => [
      'two effects are joined by a space',
      ['focal' => [300, 200], 'scale' => [800]],
      'Focal Scale and Crop (width: 300 | height: 200) Scale (width: 800)',
    ];
    $anchors = [
      'left-top',
      'center-top',
      'right-top',
      'left-center',
      'center-center',
      'right-center',
      'left-bottom',
      'center-bottom',
      'right-bottom',
    ];
    foreach ($anchors as $anchor) {
      yield 'anchor ' . $anchor => [
        'the ' . $anchor . ' anchor prints its long name',
        ['crop' => [300, 200, $anchor]],
        'Crop (width: 300 | height: 200 | anchor: ' . $anchor . ')',
      ];
    }
  }

  /**
   * It answers the smallest width and height across a multi-effect style.
   *
   * A style may carry several effects, each with its own dimensions, and the
   * getters answer the tightest constraint rather than the first or the last —
   * so the answer does not depend on the order the setters were called in.
   * Both orders are asserted for that reason.
   *
   * The second half of the criterion is the absence: a style with no effects
   * at all, and a style whose only effect carries no properties, both answer
   * nothing rather than zero.
   */
  public function testAnswersTheSmallestWidthAndHeight(): void {
    $tighterFirst = new NeoImageStyle(['focal' => [400, 300], 'scale' => [800]]);
    $this->assertSame(400, $tighterFirst->getWidth(), 'the tighter width wins when it is set first');
    $this->assertSame(300, $tighterFirst->getHeight(), 'a height set by one effect survives an effect that sets none');

    $widerFirst = new NeoImageStyle(['scale' => [800], 'focal' => [400, 300]]);
    $this->assertSame(400, $widerFirst->getWidth(), 'the tighter width wins when it is set last');
    $this->assertSame(300, $widerFirst->getHeight(), 'a height set by the later effect is still answered');

    $threeEffects = new NeoImageStyle([
      'size' => [900, 700],
      'focal' => [400, 300],
      'crop' => [600, 500, 'center-center'],
    ]);
    $this->assertSame(400, $threeEffects->getWidth(), 'the smallest width across three effects');
    $this->assertSame(300, $threeEffects->getHeight(), 'the smallest height across three effects');

    $noEffects = new NeoImageStyle();
    $this->assertNull($noEffects->getWidth(), 'a style with no effects has no width');
    $this->assertNull($noEffects->getHeight(), 'a style with no effects has no height');

    $noDimensions = new NeoImageStyle(['cropSides' => []]);
    $this->assertNull($noDimensions->getWidth(), 'an effect that carries no properties has no width');
    $this->assertNull($noDimensions->getHeight(), 'an effect that carries no properties has no height');
  }

  /**
   * It calls a private uri external, an http url external, a public uri not.
   *
   * The predicate decides whether a URI is handed to the image style at all.
   * The answer a future reader is most likely to want to change is the middle
   * one: a `private://` URI is **external** to this module, because the
   * predicate asks whether the URI is on the public stream rather than whether
   * it is remote. That decision is pinned at the URL entry points under
   * `docs/adr/0011`; this pins it at the predicate itself.
   *
   * @param string $description
   *   What the row covers.
   * @param string $uri
   *   The URI to ask about.
   * @param bool $expected
   *   The answer the predicate gives today.
   */
  #[DataProvider('providerExternalUris')]
  public function testCallsPrivateUriExternalAndPublicUriNot(string $description, string $uri, bool $expected): void {
    $this->assertSame($expected, NeoImageStyle::isExternalUri($uri), $description);
  }

  /**
   * The predicate's truth table.
   *
   * @return \Generator
   *   Rows of description, URI and expected answer.
   */
  public static function providerExternalUris(): \Generator {
    yield 'public uri' => ['a public uri is not external', 'public://logo.png', FALSE];
    yield 'public uri in a subdirectory' => ['a nested public uri is not external', 'public://2026-08/logo.png', FALSE];
    yield 'private uri' => ['a private uri is external', 'private://secret.pdf', TRUE];
    yield 'temporary uri' => ['a temporary uri is external', 'temporary://scratch.png', TRUE];
    yield 'http url' => ['an http url is external', 'http://example.com/logo.png', TRUE];
    yield 'https url' => ['an https url is external', 'https://example.com/logo.png', TRUE];
    yield 'protocol-relative url' => [
      'a protocol-relative url is external',
      '//example.com/logo.png',
      TRUE,
    ];
    yield 'relative path' => [
      'a relative path is external, because it is not on the public stream',
      'sites/default/files/logo.png',
      TRUE,
    ];
  }

  /**
   * It hands a typed getter the integer the parse answered.
   *
   * The **codec**'s parse half is the only route from a **style id** to these
   * getters — the style manager reads a **derivative directory** name and hands
   * the parsed parameters straight to a style — and `getWidth()` declares
   * `int|null` in a class with no `strict_types`. A numeric string was
   * therefore coerced and a non-numeric one was a `TypeError` reachable from
   * the image-size select on an admin form.
   *
   * The grammar closes both, and this pins the consequence at the getter, where
   * `StyleIdCodecTest` pins it at the codec: the value arrives already an
   * integer, and a width that is not digits never becomes parameters at all.
   */
  public function testHandsTypedGettersTheIntegersTheParseAnswered(): void {
    $parsed = (new NeoImageStyle())->convertIdToParams('neo-s--w-300');
    $this->assertSame(['s' => ['w' => 300]], $parsed, 'the parse answers an integer');
    $this->assertSame(300, (new NeoImageStyle())->setParameters($parsed)->getWidth(), 'which the declared int return holds with nothing to coerce');

    $this->expectException(\InvalidArgumentException::class);
    (new NeoImageStyle())->convertIdToParams('neo-s--w-zz');
  }

}
