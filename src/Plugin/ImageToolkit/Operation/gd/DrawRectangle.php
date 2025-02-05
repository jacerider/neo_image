<?php

declare(strict_types=1);

namespace Drupal\neo_image\Plugin\ImageToolkit\Operation\gd;

use Drupal\Component\Utility\Color;
use Drupal\Core\ImageToolkit\Attribute\ImageToolkitOperation;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_image\NeoImagePositionedRectangle;
use Drupal\system\Plugin\ImageToolkit\Operation\gd\GDImageToolkitOperationBase;

/**
 * Defines GD2 draw rectangle operation.
 */
#[ImageToolkitOperation(
  id: 'neo_image_gd_draw_rectangle',
  toolkit: 'gd',
  operation: 'draw_rectangle',
  label: new TranslatableMarkup('Draw rectangle'),
  description: new TranslatableMarkup('Draws  a rectangle on the image, optionally filling it in with a specified color.'),
)]
class DrawRectangle extends GDImageToolkitOperationBase {

  /**
   * {@inheritdoc}
   */
  protected function arguments() {
    return [
      'rectangle' => [
        'description' => 'A PositionedRectangle object.',
        'type' => NeoImagePositionedRectangle::class,
      ],
      'fill_color' => [
        'description' => 'The RGBA color of the polygon fill.',
        'type' => '?string',
        'required' => FALSE,
        'default' => NULL,
      ],
      'fill_color_luma' => [
        'description' => 'If TRUE, convert RGBA of the polygon fill to best match using luma.',
        'type' => 'bool',
        'required' => FALSE,
        'default' => FALSE,
      ],
      'border_color' => [
        'description' => 'The RGBA color of the polygon line.',
        'type' => '?string',
        'required' => FALSE,
        'default' => NULL,
      ],
      'border_color_luma' => [
        'description' => 'If TRUE, convert RGBA of the polygon line to best match using luma.',
        'type' => 'bool',
        'required' => FALSE,
        'default' => FALSE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function validateArguments(array $arguments) {
    // Match color luma.
    if ($arguments['fill_color'] && $arguments['fill_color_luma']) {
      $arguments['fill_color'] = $this->matchLuma($arguments['fill_color']);
    }
    if ($arguments['border_color'] && $arguments['border_color_luma']) {
      $arguments['border_color'] = $this->matchLuma($arguments['border_color']);
    }
    return $arguments;
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(array $arguments) {
    $success = TRUE;
    if ($arguments['fill_color']) {
      $color = $this->allocateColorFromRgba($this->getToolkit()->getImage(), $arguments['fill_color']);
      $success = imagefilledpolygon($this->getToolkit()->getImage(), $this->getRectangleCorners($arguments['rectangle']), $color);
    }
    if ($success && $arguments['border_color']) {
      $color = $this->allocateColorFromRgba($this->getToolkit()->getImage(), $arguments['border_color']);
      $success = imagepolygon($this->getToolkit()->getImage(), $this->getRectangleCorners($arguments['rectangle']), $color);
    }
    return $success;
  }

  /**
   * Allocates a GD color from an RGBA hexadecimal.
   *
   * @param \GDImage $image
   *   An image.
   * @param string $rgba_hex
   *   A string specifing an RGBA color in the format '#RRGGBBAA'.
   *
   * @return int
   *   A GD color index.
   */
  private function allocateColorFromRgba(\GDImage $image, string $rgba_hex): int {
    [$r, $g, $b, $alpha] = array_values($this->hexToRgba($rgba_hex));
    return imagecolorallocatealpha($image, $r, $g, $b, $alpha);
  }

  /**
   * Convert a RGBA hex to its RGBA integer GD components.
   *
   * GD expects a value between 0 and 127 for alpha, where 0 indicates
   * completely opaque while 127 indicates completely transparent.
   * RGBA hexadecimal notation has #00 for transparent and #FF for
   * fully opaque.
   *
   * @param string $rgba_hex
   *   A string specifing an RGBA color in the format '#RRGGBBAA'.
   *
   * @return array|false
   *   An array with four elements for red, green, blue, and alpha.
   */
  private function hexToRgba(string $rgba_hex): array|FALSE {
    $rgbHex = mb_substr($rgba_hex, 0, 7);
    try {
      $rgb = Color::hexToRgb($rgbHex);
      $opacity = $this->rgbaToOpacity($rgba_hex);
      $alpha = 127 - (int) floor(($opacity / 100) * 127);
      $rgb['alpha'] = $alpha;
      return $rgb;
    }
    catch (\InvalidArgumentException $e) {
      return FALSE;
    }
  }

  /**
   * Convert RGBA alpha to percent opacity.
   *
   * @param string $rgba
   *   RGBA hexadecimal.
   *
   * @return int
   *   Opacity as percentage (0 = transparent, 100 = fully opaque).
   */
  public function rgbaToOpacity(string $rgba): int {
    if (!$this->validateRgba($rgba)) {
      if (Color::validateHex($rgba)) {
        return 100;
      }
      throw new \InvalidArgumentException("Invalid color '$rgba' specified for " . __METHOD__);
    }
    return (int) floor(hexdec(substr($rgba, -2)) / 255 * 100);
  }

  /**
   * Validates whether a hexadecimal RGBA color value is syntactically correct.
   *
   * @param string $hex
   *   The hexadecimal string to validate. Must contain a leading '#'. Must use
   *   the long notation (i.e. '#RRGGBBAA').
   *
   * @return bool
   *   TRUE if $hex is valid or FALSE if it is not.
   */
  public function validateRgba(string $hex): bool {
    return preg_match('/^#([0-9a-fA-F]{8})$/', $hex) === 1;
  }

  /**
   * Convert a rectangle to a sequence of point coordinates.
   *
   * GD requires a simple array of point coordinates in its
   * imagepolygon() function.
   *
   * @param \Drupal\neo_image\NeoImagePositionedRectangle $rect
   *   A PositionedRectangle object.
   *
   * @return array
   *   A simple array of 8 point coordinates.
   */
  private function getRectangleCorners(NeoImagePositionedRectangle $rect): array {
    $points = [];
    foreach (['c_d', 'c_c', 'c_b', 'c_a'] as $c) {
      $point = $rect->getPoint($c);
      $points[] = $point[0];
      $points[] = $point[1];
    }
    return $points;
  }

  /**
   * Determine best match to over/underlay a defined color.
   *
   * Calculates UCCIR 601 luma of the entered color and returns a black or
   * white color to ensure readibility.
   *
   * @see http://en.wikipedia.org/wiki/Luma_video
   */
  public function matchLuma(string $rgba, bool $soft = FALSE): string {
    $rgb = mb_substr($rgba, 0, 7);
    [$r, $g, $b] = array_values(Color::hexToRgb($rgb));
    $luma = 1 - (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    if ($luma < 0.5) {
      // Bright colors - black.
      $d = 0;
    }
    else {
      // Dark colors - white.
      $d = 255;
    }
    return Color::rgbToHex([$d, $d, $d]);
  }

}
