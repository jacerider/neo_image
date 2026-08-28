<?php

declare(strict_types=1);

namespace Drupal\neo_image\Routing;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\neo_image\NeoImageStyle;
use Symfony\Component\Routing\Route;

/**
 * Provides upcasting for an image style.
 *
 * Load entity if found or pass string for dynamic styles.
 */
class NeoImageStyleConverter implements ParamConverterInterface {

  /**
   * Constructs a NeoImageStyleConverter object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * A name the **codec** refuses answers NULL, which is what this converter
   * already answers for an unknown style name that does not start `neo-`, and
   * which Drupal turns into a 404. Before that, a **rejected id** reached the
   * route as an uncaught exception: a crafted URL was a WSOD, and a mistyped
   * one built a junk style and wrote a **derivative directory** nobody could
   * ask for again.
   *
   * It stays silent on the way out, deliberately. The name arrives from a URL
   * an attacker controls, so logging here is a log-flood vector and the 404 in
   * the access log is already the record. The **style manager** is where a
   * refusal is worth saying out loud, because a directory is site state.
   */
  public function convert($value, $definition, $name, array $defaults) {
    $storage = $this->entityTypeManager->getStorage('image_style');
    if ($image_style = $storage->load($value)) {
      return $image_style;
    }
    elseif (substr($value, 0, 4) === 'neo-') {
      $neoImageStyle = new NeoImageStyle();
      try {
        $neoImageStyle->setParameters($neoImageStyle->convertIdToParams($value));
      }
      catch (\InvalidArgumentException) {
        return NULL;
      }
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
