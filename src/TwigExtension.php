<?php

namespace Drupal\neo_image;

use Drupal\Core\Template\Attribute;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Defines Twig extensions.
 */
class TwigExtension extends AbstractExtension {

  /**
   * Gets a unique identifier for this Twig extension.
   *
   * @return string
   *   A unique identifier for this Twig extension.
   */
  public function getName() {
    return 'twig.neo_image';
  }

  /**
   * {@inheritdoc}
   */
  public function getFunctions() {
    return [
      new TwigFunction('neo_image', [$this, 'renderImage']),
      new TwigFunction('neo_image_style', [$this, 'renderImageStyle']),
      new TwigFunction('neo_image_style_url', [$this, 'renderImageStyleUrl']),
    ];
  }

  /**
   * Swap placeholder image sizes in the URL.
   */
  protected static function placeholderSwap($mixed, $options = []) {
    if (is_string($mixed) && is_array($options) && !empty($options) && NeoImageStyle::isExternalUri($mixed)) {
      if (preg_match('/\/(\d+)x(\d+)\.png$/', $mixed, $matches)) {
        $width = $matches[1];
        $height = $matches[2];
        // For placeholder images such as https://placehold.co/300x200.png,
        // adjust the size in the URL to match the selected size.
        if ($width && $height) {
          if (strpos($mixed, $width . 'x' . $height) !== FALSE) {
            $sizeWidth = 0;
            $sizeHeight = 0;
            foreach ($options as $sizeValues) {
              if (isset($sizeValues['width'])) {
                $sizeWidth = $sizeValues['width'] > $sizeWidth ? $sizeValues['width'] : $sizeWidth;
              }
              if (isset($sizeValues['height'])) {
                $sizeHeight = $sizeValues['height'] > $sizeHeight ? $sizeValues['height'] : $sizeHeight;
              }
            }
            $finalSize = ($sizeWidth ?: $width) . 'x' . ($sizeHeight ?: $height);
            $mixed = str_replace($width . 'x' . $height, $finalSize, $mixed);
          }
        }
      }
    }
    return $mixed;
  }

  /**
   * Render the neo image style.
   */
  public static function renderImage($mixed, $options = [], $alt = '', $title = '', $attributes = []) {
    $build = [];
    $mixed = self::placeholderSwap($mixed, $options);
    if (!is_array($options)) {
      // If options is not an array, ignore it.
      $options = [];
    }
    if (!array_intersect_key($options, array_flip(['sm', 'md', 'lg', 'xl', '2xl']))) {
      return self::renderImageStyle($mixed, $options, $alt, $title, $attributes);
    }
    if ($attributes instanceof Attribute) {
      $attributes = $attributes->toArray();
    }
    if (is_string($mixed)) {
      $uri = NeoImageStyle::rewritePublicPath($mixed);
      if (NeoImageStyle::isExternalUri($uri)) {
        return self::renderImageStyle($mixed, [], $alt, $title, $attributes);
      }
      $neoImage = new NeoImage($uri);
      $neoImage->autoFromDimensions($options);
      $build = $neoImage->toRenderable($alt, $title, $attributes);
    }
    elseif ($mixed instanceof MediaInterface || $mixed instanceof FileInterface) {
      $neoImage = NeoImage::createFromEntity($mixed);
      $build = $neoImage->toRenderable($alt, $title, $attributes);
    }
    return $build;
  }

  /**
   * Render the neo image style.
   */
  public static function renderImageStyle($mixed, $options = [], $alt = '', $title = '', $attributes = []) {
    $build = [];
    $mixed = self::placeholderSwap($mixed, $options);
    if (!is_array($options)) {
      // If options is not an array, ignore it.
      $options = [];
    }
    if ($options && array_intersect_key($options, array_flip(['sm', 'md', 'lg', 'xl', '2xl']))) {
      return self::renderImage($mixed, $options, $alt, $title, $attributes);
    }
    if ($attributes instanceof Attribute) {
      $attributes = $attributes->toArray();
    }
    if (is_string($mixed)) {
      $neoImageStyle = new NeoImageStyle($options);
      $build = $neoImageStyle->toRenderableFromUri($mixed, $alt, $title, $attributes);
    }
    elseif ($mixed instanceof MediaInterface || $mixed instanceof FileInterface) {
      $neoImageStyle = new NeoImageStyle($options);
      $build = $neoImageStyle->toRenderableFromEntity($mixed, $alt, $title, $attributes);
    }
    return $build;
  }

  /**
   * Render the neo image style.
   */
  public static function renderImageStyleUrl($mixed, array $options = [], $alt = '', $title = '') {
    $build = [];
    $mixed = self::placeholderSwap($mixed, $options);
    if (is_string($mixed)) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toUrlFromUri($mixed);
    }
    elseif ($mixed instanceof MediaInterface || $mixed instanceof FileInterface) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toUrlFromEntity($mixed);
    }
    return $build;
  }

}
