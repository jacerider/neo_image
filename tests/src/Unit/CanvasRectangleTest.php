<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\NeoImagePositionedRectangle;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the surviving surface of the canvas rectangle.
 *
 * `NeoImagePositionedRectangle` is the vendored effect fork's rectangle class,
 * and the only thing the **exact effect** and its GD draw operation ask of it
 * is where the four corners of an axis-aligned canvas are. The effect builds
 * one from a width and a height; the draw operation reads `c_d`, `c_c`, `c_b`
 * and `c_a` off it with `getPoint()` and fills the polygon they describe.
 * Nothing rotates it, translates it, resizes it or asks it for a grid.
 *
 * This test characterises exactly that surviving surface — construction,
 * the corners, the bounding box, the two dimensions and the point round-trip —
 * against the code as it stands, so that the deletion of the unreachable
 * algebra has a specification to satisfy rather than a claim to make.
 *
 * Two details of the arithmetic are what the pin exists for:
 *
 * - **The corners sit at inclusive bounds.** A canvas of width `w` and height
 *   `h` puts corners at `[0, 0]`, `[w - 1, 0]`, `[w - 1, h - 1]`, `[0, h - 1]`.
 *   The bounding box is then computed back from those corners as their span
 *   *plus one*, which is how the dimensions come out equal to the canvas
 *   again. The `-1` and the `+1` only make sense as a pair, so both halves are
 *   asserted.
 * - **A one-by-one canvas collapses all four corners onto the origin.** It is
 *   the edge of that same arithmetic and the cheapest regression detector for
 *   it.
 *
 * Assertions go through `getPoint()` by corner id, never through the
 * whole-point-set getter, because that getter is itself scheduled for deletion
 * and a test written through it would have to be rewritten by the very change
 * it exists to constrain.
 *
 * Pure unit: the class has no services, no entities, no database and no state
 * beyond its own arrays.
 */
#[Group('neo_image')]
final class CanvasRectangleTest extends UnitTestCase {

  /**
   * It places the four corners at the inclusive bounds of its dimensions.
   *
   * The canvas the **exact effect** fills is 1200x630 on this site — the
   * social-image size — and its far corners land on 1199 and 629, not on 1200
   * and 630.
   */
  public function testPlacesCornersAtInclusiveBounds(): void {
    $rectangle = new NeoImagePositionedRectangle(1200, 630);

    $this->assertSame([0, 0], $rectangle->getPoint('c_a'), 'the near corner is the origin');
    $this->assertSame([1199, 0], $rectangle->getPoint('c_b'), 'the far x is width - 1');
    $this->assertSame([1199, 629], $rectangle->getPoint('c_c'), 'the far corner is one inside both dimensions');
    $this->assertSame([0, 629], $rectangle->getPoint('c_d'), 'the far y is height - 1');

    // A second, differently shaped canvas, so the rule is read as a rule and
    // not as one memorised quadruple.
    $tall = new NeoImagePositionedRectangle(3, 7);

    $this->assertSame([0, 0], $tall->getPoint('c_a'));
    $this->assertSame([2, 0], $tall->getPoint('c_b'));
    $this->assertSame([2, 6], $tall->getPoint('c_c'));
    $this->assertSame([0, 6], $tall->getPoint('c_d'));
  }

  /**
   * It answers a bounding box one pixel larger than the corners' span.
   *
   * This is the other half of the inclusive-bounds arithmetic: the box is
   * derived back from the corners, and the `+1` is what returns the canvas its
   * own width and height. Asserting the span from the corners that were just
   * read — rather than restating the constants — is what pins the two halves
   * together.
   */
  public function testAnswersBoundingBoxOnePixelLargerThanTheCornerSpan(): void {
    $rectangle = new NeoImagePositionedRectangle(1200, 630);

    $near = $rectangle->getPoint('c_a');
    $far = $rectangle->getPoint('c_c');

    $this->assertSame(
      $far[0] - $near[0] + 1,
      $rectangle->getBoundingWidth(),
      'the bounding width is the horizontal span of the corners, plus one'
    );
    $this->assertSame(
      $far[1] - $near[1] + 1,
      $rectangle->getBoundingHeight(),
      'the bounding height is the vertical span of the corners, plus one'
    );

    // And the round trip closes: the box is the canvas it was built from.
    $this->assertSame(1200, $rectangle->getBoundingWidth(), 'the box is as wide as the canvas');
    $this->assertSame(630, $rectangle->getBoundingHeight(), 'the box is as tall as the canvas');
    $this->assertSame(1200, $rectangle->getWidth(), 'the dimensions come back equal to the canvas');
    $this->assertSame(630, $rectangle->getHeight());
  }

  /**
   * It answers its width and height from the corners it was set from.
   *
   * Corners may be handed in directly rather than derived from dimensions, and
   * they need not sit at the origin. Setting them is what computes the
   * dimensions, as the corner-setting entry's last act — so these getters are
   * the type's statement of its own size, whoever supplied the corners.
   */
  public function testAnswersDimensionsFromTheCornersItWasSetFrom(): void {
    $rectangle = new NeoImagePositionedRectangle();
    $rectangle->setFromCorners([
      'c_a' => [10, 20],
      'c_b' => [39, 20],
      'c_c' => [39, 69],
      'c_d' => [10, 69],
    ]);

    $this->assertSame(30, $rectangle->getWidth(), 'thirty pixels inclusive of both ends');
    $this->assertSame(50, $rectangle->getHeight(), 'fifty pixels inclusive of both ends');
    $this->assertSame(30, $rectangle->getBoundingWidth(), 'the box agrees with the width');
    $this->assertSame(50, $rectangle->getBoundingHeight(), 'the box agrees with the height');

    // The corners are kept exactly as given — an offset rectangle is not
    // normalised back to the origin.
    $this->assertSame([10, 20], $rectangle->getPoint('c_a'));
    $this->assertSame([39, 20], $rectangle->getPoint('c_b'));
    $this->assertSame([39, 69], $rectangle->getPoint('c_c'));
    $this->assertSame([10, 69], $rectangle->getPoint('c_d'));
  }

  /**
   * It keeps and answers a point set under an arbitrary id.
   *
   * The point store is a plain map keyed by id: the four corner ids are only a
   * convention over it, and the draw operation reads through the same getter
   * any other id is read through.
   */
  public function testKeepsAndAnswersPointUnderArbitraryId(): void {
    $rectangle = new NeoImagePositionedRectangle(40, 30);

    $returned = $rectangle->setPoint('anchor', [12, 34]);

    $this->assertSame($rectangle, $returned, 'the setter is chainable');
    $this->assertSame([12, 34], $rectangle->getPoint('anchor'), 'the point comes back as it went in');

    // An arbitrary point does not disturb the canvas corners or the box.
    $this->assertSame([39, 29], $rectangle->getPoint('c_c'), 'the far corner is untouched');
    $this->assertSame(40, $rectangle->getWidth(), 'the width is untouched');
    $this->assertSame(30, $rectangle->getHeight(), 'the height is untouched');

    // Setting the same id again replaces the coordinates.
    $rectangle->setPoint('anchor', [56, 78]);
    $this->assertSame([56, 78], $rectangle->getPoint('anchor'), 'the id is overwritten, not appended to');
  }

  /**
   * It answers the same rectangle, constructed or set afterwards.
   *
   * The **exact effect** builds its canvas through the constructor; the two
   * paths must not diverge, because the deletion touches the constructor's
   * neighbourhood.
   */
  public function testAnswersTheSameRectangleWhicheverWayTheDimensionsArrive(): void {
    $constructed = new NeoImagePositionedRectangle(1200, 630);

    $afterwards = new NeoImagePositionedRectangle();
    $afterwards->setFromDimensions(1200, 630);

    foreach (['c_a', 'c_b', 'c_c', 'c_d'] as $corner) {
      $this->assertSame(
        $constructed->getPoint($corner),
        $afterwards->getPoint($corner),
        sprintf('corner %s is the same either way', $corner)
      );
    }

    $this->assertSame($constructed->getWidth(), $afterwards->getWidth(), 'the width is the same either way');
    $this->assertSame($constructed->getHeight(), $afterwards->getHeight(), 'the height is the same either way');
    $this->assertSame($constructed->getBoundingWidth(), $afterwards->getBoundingWidth());
    $this->assertSame($constructed->getBoundingHeight(), $afterwards->getBoundingHeight());

    // An empty construction has no dimensions until it is given some.
    $empty = new NeoImagePositionedRectangle();
    $this->assertSame(0, $empty->getWidth(), 'an unset rectangle has no width');
    $this->assertSame(0, $empty->getHeight(), 'an unset rectangle has no height');
  }

  /**
   * It answers a single-pixel rectangle for a one-by-one canvas.
   *
   * The edge of the inclusive-bounds arithmetic: with `width - 1` and
   * `height - 1` both zero, all four corners collapse onto the origin, and the
   * `+1` still returns a one-by-one box. The polygon the fill receives is four
   * copies of a single point, and it paints that pixel.
   */
  public function testAnswersSinglePixelRectangleForOneByOneCanvas(): void {
    $rectangle = new NeoImagePositionedRectangle(1, 1);

    $this->assertSame([0, 0], $rectangle->getPoint('c_a'), 'every corner collapses onto the origin');
    $this->assertSame([0, 0], $rectangle->getPoint('c_b'));
    $this->assertSame([0, 0], $rectangle->getPoint('c_c'));
    $this->assertSame([0, 0], $rectangle->getPoint('c_d'));

    $this->assertSame(1, $rectangle->getBoundingWidth(), 'a zero span is still one pixel wide');
    $this->assertSame(1, $rectangle->getBoundingHeight(), 'a zero span is still one pixel tall');
    $this->assertSame(1, $rectangle->getWidth(), 'the canvas keeps its width');
    $this->assertSame(1, $rectangle->getHeight(), 'the canvas keeps its height');
  }

}
