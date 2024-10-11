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
      new TwigFunction('neo_image_style', [$this, 'renderImageStyle']),
    ];
  }

  /**
   * Render the neo image style.
   */
  public static function renderImageStyle($mixed, array $options = [], $alt = '', $title = '', $attributes = []) {
    $build = [];
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

}
