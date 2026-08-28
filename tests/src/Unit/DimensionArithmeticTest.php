<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\NeoImageUtility;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises `NeoImageUtility`'s dimension arithmetic.
 *
 * `percentFilter()` and `resizeDimensions()` are static, take scalars and
 * answer scalars: no container, no database, no entity, no image. They are the
 * arithmetic every neo_image effect resolves a requested size through, and
 * neither had a test.
 *
 * The interesting half is the absence. A specification may be relative — `50%`
 * of a source dimension — and a source dimension may be unknown, because a
 * remote or unreadable image answers no width and no height. Where the
 * arithmetic cannot be done, both functions answer nothing rather than zero,
 * and `resizeDimensions()` answers nothing for **both** dimensions rather than
 * the one it could have computed. That all-or-nothing rule is what a caller
 * relies on to decide whether to emit `width` and `height` attributes at all.
 */
#[Group('neo_image')]
final class DimensionArithmeticTest extends UnitTestCase {

  /**
   * It computes percentage and absolute dimensions, and nothing without source.
   *
   * The three cases the arithmetic distinguishes: an absolute specification,
   * which needs no source dimension; a percentage specification, which needs
   * one; and an unknown source dimension, which answers nothing.
   *
   * `resizeDimensions()` adds the aspect-ratio completion on top — one
   * specification given, the other derived from the source's ratio, or from a
   * square when the caller asks for one — and the same all-or-nothing rule
   * when the source is unknown.
   */
  public function testComputesPercentageAndAbsoluteDimensions(): void {
    // An absolute specification is answered as an integer, source or no source.
    $this->assertSame(50, NeoImageUtility::percentFilter(50, 400), 'an absolute specification ignores the source');
    $this->assertSame(50, NeoImageUtility::percentFilter(50, NULL), 'an absolute specification needs no source');
    $this->assertSame(50, NeoImageUtility::percentFilter('50', 400), 'a numeric string is an absolute specification');

    // A percentage specification is resolved against the source.
    $this->assertSame(200, NeoImageUtility::percentFilter('50%', 400), 'a percentage resolves against the source');
    $this->assertSame(100, NeoImageUtility::percentFilter('25%', 400), 'a quarter of the source');
    $this->assertSame(40, NeoImageUtility::percentFilter('10%', 400), 'a tenth of the source');

    // Nothing, where there is nothing to compute from.
    $this->assertNull(NeoImageUtility::percentFilter('50%', NULL), 'a percentage of an unknown source is nothing');
    $this->assertNull(NeoImageUtility::percentFilter(NULL, 400), 'no specification is nothing');
    $this->assertNull(NeoImageUtility::percentFilter(NULL, NULL), 'no specification and no source is nothing');

    // Both dimensions given: absolute, then percentage.
    $this->assertSame(
      ['width' => 200, 'height' => 150],
      NeoImageUtility::resizeDimensions(400, 300, 200, 150),
      'two absolute specifications are answered as given'
    );
    $this->assertSame(
      ['width' => 200, 'height' => 150],
      NeoImageUtility::resizeDimensions(400, 300, '50%', '50%'),
      'two percentage specifications resolve against their own source dimension'
    );

    // One dimension given: the other comes from the source's aspect ratio.
    $this->assertSame(
      ['width' => 200, 'height' => 150],
      NeoImageUtility::resizeDimensions(400, 300, 200, NULL),
      'a missing height is derived from the source ratio'
    );
    $this->assertSame(
      ['width' => 200, 'height' => 150],
      NeoImageUtility::resizeDimensions(400, 300, NULL, 150),
      'a missing width is derived from the source ratio'
    );
    $this->assertSame(
      ['width' => 200, 'height' => 200],
      NeoImageUtility::resizeDimensions(400, 300, 200, NULL, TRUE),
      'a square request derives the missing dimension from a 1:1 ratio'
    );

    // Neither dimension given: nothing to compute, and no ratio is invented.
    $this->assertSame(
      ['width' => NULL, 'height' => NULL],
      NeoImageUtility::resizeDimensions(400, 300, NULL, NULL),
      'no specification answers nothing even with a known source'
    );

    // An unknown source dimension discards the dimension that was computable.
    $this->assertSame(
      ['width' => NULL, 'height' => NULL],
      NeoImageUtility::resizeDimensions(NULL, NULL, 200, NULL),
      'an unknown source discards the width it could have answered'
    );
    $this->assertSame(
      ['width' => NULL, 'height' => NULL],
      NeoImageUtility::resizeDimensions(NULL, 300, 200, NULL),
      'one unknown source dimension is enough to discard both'
    );
    $this->assertSame(
      ['width' => NULL, 'height' => NULL],
      NeoImageUtility::resizeDimensions(NULL, NULL, '50%', '50%'),
      'percentages of an unknown source answer nothing'
    );
  }

}
