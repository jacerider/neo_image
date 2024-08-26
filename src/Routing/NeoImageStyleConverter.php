<?php

declare(strict_types = 1);

namespace Drupal\neo_image\Routing;

use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\neo_image\NeoImageStyle;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Route;

/**
 * Provides upcasting for an image style.
 *
 * Load entity if found or pass string for dynamic styles.
 */
class NeoImageStyleConverter implements ParamConverterInterface {

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    if ($image_style = ImageStyle::load($value)) {
      return $image_style;
    }
    elseif (substr($value, 0, 4) === 'neo-') {
      $neoImageStyle = new NeoImageStyle();
      $neoImageStyle->setParameters($neoImageStyle->convertIdToParams($value));
      // parse_str(base64_decode(strtr(substr($value, 4), '-_.', '+/=')), $params);
      // $neoImageStyle = new NeoImageStyle();
      // $neoImageStyle->setParameters($params);
      return $neoImageStyle->getImageStyle();
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return isset($definition['type']) && $definition['type'] == 'image_style_dynamic';
  }

}
