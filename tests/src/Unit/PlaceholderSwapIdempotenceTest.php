<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the **placeholder swap** to one run inside the **render dispatch**.
 *
 * The swap rewrites the trailing `{width}x{height}` of an external URL to the
 * largest width and the largest height the options name, each falling back to
 * the matched value when the options name none.
 *
 * Two things have to hold together for the collapse to be safe, and this
 * file asserts both. The dispatch runs the swap **once**, before it chooses a
 * shape, where the mutual delegation it replaces ran it once per hop — two or
 * three times per render. And the swap is **idempotent**: a second pass
 * matches the value the first pass wrote and computes the same maxima from
 * the same options, so it writes the same string. Without the second fact the
 * first would be a behaviour change rather than a no-op, and nothing said
 * either out loud until this test did.
 *
 * **Why unit.** The swap is string handling over an options array, and the
 * dispatch's own decision is an `array_intersect_key`. Neither reaches an
 * entity, a stream wrapper or rendered markup, so the probe stubs out the two
 * shape builders and a container would add nothing to assert against.
 */
#[Group('neo_image')]
final class PlaceholderSwapIdempotenceTest extends UnitTestCase {

  /**
   * A placeholder URL of the shape the swap recognises.
   */
  private const PLACEHOLDER = 'https://placehold.co/300x200.png';

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    DispatchProbeTwigExtension::reset();
    parent::tearDown();
  }

  /**
   * It resolves the dimensions once, and a second pass changes nothing.
   *
   * The dispatch is the unit under test here, not the swap: what is asserted
   * is how many times the swap ran and what the chosen shape received, which
   * is a fact about the dispatch and about nothing else. The delegation cycle
   * this replaces has no dispatch to ask, which is what makes this red.
   */
  public function testItResolvesThePlaceholderUrlDimensionsOnceAndSecondPassChangesNothing(): void {
    DispatchProbeTwigExtension::reset();
    $options = [
      'sm' => ['width' => 320, 'height' => 240],
      'lg' => ['width' => 960, 'height' => 720],
    ];

    DispatchProbeTwigExtension::dispatch(self::PLACEHOLDER, $options);

    // Once, on the subject as it arrived. Two entries would be the cycle.
    $this->assertSame([self::PLACEHOLDER], DispatchProbeTwigExtension::$swapped);

    // And before the shape was chosen, because the shape received the
    // resolved URL rather than the one the caller passed.
    $this->assertSame(
      [['responsive', 'https://placehold.co/960x720.png']],
      DispatchProbeTwigExtension::$shapes
    );

    // A second pass over the dispatch's own output changes nothing, which is
    // why running the swap once answers what running it two or three times
    // answered.
    $this->assertSame(
      'https://placehold.co/960x720.png',
      $this->swap('https://placehold.co/960x720.png', $options)
    );
  }

  /**
   * A second pass changes nothing, at every option shape a template reaches.
   *
   * The idempotence above, widened to every shape rather than the one the
   * dispatch was driven with. These expectations are characterisation: the
   * swap is unchanged by this ticket, and they hold before it as well as
   * after. They are here because the collapse rests on them.
   *
   * @param string $description
   *   What the row covers.
   * @param mixed $options
   *   The options the swap reads its maxima from.
   * @param string $expected
   *   What one pass answers.
   */
  #[DataProvider('providerOptionShapes')]
  public function testSecondPassOverItsOwnOutputChangesNothing(string $description, mixed $options, string $expected): void {
    $once = $this->swap(self::PLACEHOLDER, $options);
    $this->assertSame($expected, $once, $description);

    $twice = $this->swap($once, $options);
    $this->assertSame($once, $twice, $description);
  }

  /**
   * Every option shape a template can reach the swap with.
   *
   * @return array<int, array{string, mixed, string}>
   *   Description, options, and the URL one pass answers.
   */
  public static function providerOptionShapes(): array {
    return [
      [
        'effect-keyed options name the size',
        ['size' => ['width' => 640, 'height' => 480]],
        'https://placehold.co/640x480.png',
      ],
      [
        'breakpoint options answer their largest width and largest height',
        [
          'sm' => ['width' => 320, 'height' => 240],
          'lg' => ['width' => 960, 'height' => 720],
          'xl' => ['width' => 800, 'height' => 900],
        ],
        'https://placehold.co/960x900.png',
      ],
      [
        'options naming no dimensions keep the matched size',
        ['crop' => ['anchor' => 'c']],
        self::PLACEHOLDER,
      ],
      [
        'empty options are not read at all',
        [],
        self::PLACEHOLDER,
      ],
      [
        'a non-array options value is ignored',
        'not an array',
        self::PLACEHOLDER,
      ],
    ];
  }

  /**
   * Calls the extension's private **placeholder swap**.
   *
   * It is reached by reflection rather than promoted to public: the swap is
   * an implementation detail of the **render dispatch**, and widening a
   * shared package's surface to test it would be a contract change made for
   * the test's convenience.
   *
   * @param mixed $subject
   *   The subject to swap.
   * @param mixed $options
   *   The options to read the maxima from.
   *
   * @return mixed
   *   What the swap answers.
   */
  private function swap(mixed $subject, mixed $options): mixed {
    $method = new \ReflectionMethod(TwigExtension::class, 'placeholderSwap');
    $method->setAccessible(TRUE);
    return $method->invoke(NULL, $subject, $options);
  }

}
