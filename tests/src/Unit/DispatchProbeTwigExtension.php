<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\neo_image\TwigExtension;

/**
 * A TwigExtension whose **render dispatch** reports what it did.
 *
 * The dispatch is the unit under test and it is private, so the probe reaches
 * it the way a subclass does: it exposes it, and it overrides the three steps
 * the dispatch drives so their calls can be counted. The two shape builders
 * answer an empty array rather than delegating, which is what keeps this a
 * unit test — a real **responsive render** reaches the public-files base path
 * and a real **single-style render** builds a render array, and neither is
 * what "how many times did the swap run" is asking about.
 *
 * The overrides also prove the dispatch is late-bound: it reaches its steps
 * through `static::`, so a decorated or subclassed extension replaces them.
 * A `self::`-bound call would run the parent's step and record nothing.
 */
final class DispatchProbeTwigExtension extends TwigExtension {

  /**
   * The subject handed to the **placeholder swap**, one entry per call.
   *
   * @var array<int, mixed>
   */
  public static array $swapped = [];

  /**
   * The shape chosen and the subject it received, one entry per call.
   *
   * @var array<int, array<int, mixed>>
   */
  public static array $shapes = [];

  /**
   * Forgets everything recorded so far.
   */
  public static function reset(): void {
    static::$swapped = [];
    static::$shapes = [];
  }

  /**
   * Runs the **render dispatch** under the probe.
   *
   * @param mixed $mixed
   *   The subject to render.
   * @param mixed $options
   *   The options to render it under.
   * @param mixed $alt
   *   The alt text.
   * @param mixed $title
   *   The title text.
   * @param mixed $attributes
   *   The attributes.
   *
   * @return mixed
   *   Whatever the dispatch answers.
   */
  public static function dispatch($mixed, $options = [], $alt = '', $title = '', $attributes = []) {
    return static::renderDispatch($mixed, $options, $alt, $title, $attributes);
  }

  /**
   * {@inheritdoc}
   */
  protected static function placeholderSwap($mixed, $options = []) {
    static::$swapped[] = $mixed;
    return parent::placeholderSwap($mixed, $options);
  }

  /**
   * {@inheritdoc}
   */
  protected static function responsiveRender($mixed, array $options, $alt, $title, $attributes) {
    static::$shapes[] = ['responsive', $mixed];
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected static function singleStyleRender($mixed, array $options, $alt, $title, $attributes) {
    static::$shapes[] = ['single-style', $mixed];
    return [];
  }

}
