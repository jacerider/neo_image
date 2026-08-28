<?php

declare(strict_types=1);

namespace Drupal\neo_image;

/**
 * An axis-aligned rectangle, described by the coordinates of its corners.
 *
 * It is built from a width and a height, and its four corners are read back to
 * describe the polygon a canvas fill paints.
 *
 * @internal
 *   Part of neo_image's vendored fork of the exact image effect. It is
 *   constructed and read inside this module only, and is not a public API.
 */
class NeoImagePositionedRectangle {

  /**
   * An array of point coordinates, keyed by an id.
   *
   * Canonical points are:
   * 'c_a' - bottom left corner of the rectangle
   * 'c_b' - bottom right corner of the rectangle
   * 'c_c' - top right corner of the rectangle
   * 'c_d' - top left corner of the rectangle
   * 'o_a' - bottom left corner of the box bounding the rectangle
   * 'o_c' - top right corner of the box bounding the rectangle
   * The rectangle is always axis-aligned, so the bounding corners span the
   * same extent its own corners do. Additional points can be added through
   * the setPoint() method and read back through getPoint(); they are stored
   * alongside the canonical ones and do not take part in the bounding box.
   */
  protected array $points = [];

  /**
   * The width of the rectangle.
   */
  protected int $width = 0;

  /**
   * The height of the rectangle.
   */
  protected int $height = 0;

  /**
   * NeoImagePositionedRectangle constructor.
   *
   * @param int $width
   *   The width of the rectangle.
   * @param int $height
   *   The height of the rectangle.
   */
  public function __construct(int $width = 0, int $height = 0) {
    if ($width !== 0 && $height !== 0) {
      $this->setFromDimensions($width, $height);
    }
  }

  /**
   * Sets a rectangle from its width and height.
   *
   * @param int $width
   *   The width of the rectangle.
   * @param int $height
   *   The height of the rectangle.
   *
   * @return $this
   */
  public function setFromDimensions(int $width, int $height): static {
    $this->setFromCorners([
      'c_a' => [0, 0],
      'c_b' => [$width - 1, 0],
      'c_c' => [$width - 1, $height - 1],
      'c_d' => [0, $height - 1],
    ]);
    return $this;
  }

  /**
   * Sets a rectangle from the coordinates of its corners.
   *
   * @param array $corners
   *   An associative array of point coordinates. The keys 'c_a', 'c_b',
   *   'c_c' and 'c_d' represent each of the four a, b, c, d corners of the
   *   rectangle in the format
   *   D +-----------------+ C
   *     |                 |
   *     |                 |
   *   A +-----------------+ B.
   *
   * @return $this
   */
  public function setFromCorners(array $corners): static {
    $this
      ->setPoint('c_a', $corners['c_a'])
      ->setPoint('c_b', $corners['c_b'])
      ->setPoint('c_c', $corners['c_c'])
      ->setPoint('c_d', $corners['c_d'])
      ->determineBoundingCorners();
    $this->width = $this->getBoundingWidth();
    $this->height = $this->getBoundingHeight();
    return $this;
  }

  /**
   * Sets a point and its coordinates.
   *
   * @param string $id
   *   The point ID.
   * @param array $coords
   *   An array of x, y coordinates.
   *
   * @return $this
   */
  public function setPoint(string $id, array $coords = [0, 0]): static {
    assert(is_int($coords[0]));
    assert(is_int($coords[1]));
    $this->points[$id] = $coords;
    return $this;
  }

  /**
   * Gets the coordinates of a point.
   *
   * @param string $id
   *   The point ID.
   *
   * @return array
   *   An array of x, y coordinates.
   */
  public function getPoint(string $id): array {
    return $this->points[$id];
  }

  /**
   * Gets the width of the rectangle.
   *
   * @return int
   *   The width of the rectangle.
   */
  public function getWidth(): int {
    return $this->width;
  }

  /**
   * Gets the height of the rectangle.
   *
   * @return int
   *   The height of the rectangle.
   */
  public function getHeight(): int {
    return $this->height;
  }

  /**
   * Gets the bounding width of the rectangle.
   *
   * @return int
   *   The bounding width of the rectangle.
   */
  public function getBoundingWidth(): int {
    return $this->points['o_c'][0] - $this->points['o_a'][0] + 1;
  }

  /**
   * Gets the bounding height of the rectangle.
   *
   * @return int
   *   The bounding height of the rectangle.
   */
  public function getBoundingHeight(): int {
    return $this->points['o_c'][1] - $this->points['o_a'][1] + 1;
  }

  /**
   * Calculates the corners of the bounding box.
   *
   * The bottom left ('o_a') and top right ('o_c') corners of the box around
   * the rectangle are what the bounding width and height are measured from.
   *
   * @return $this
   */
  protected function determineBoundingCorners(): static {
    $this
      ->setPoint('o_a', [
        min($this->points['c_a'][0], $this->points['c_b'][0], $this->points['c_c'][0], $this->points['c_d'][0]),
        min($this->points['c_a'][1], $this->points['c_b'][1], $this->points['c_c'][1], $this->points['c_d'][1]),
      ])
      ->setPoint('o_c', [
        max($this->points['c_a'][0], $this->points['c_b'][0], $this->points['c_c'][0], $this->points['c_d'][0]),
        max($this->points['c_a'][1], $this->points['c_b'][1], $this->points['c_c'][1], $this->points['c_d'][1]),
      ]);
    return $this;
  }

}
