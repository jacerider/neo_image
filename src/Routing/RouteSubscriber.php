<?php

declare(strict_types = 1);

namespace Drupal\neo_image\Routing;

use Drupal\Core\Routing\RouteBuildEvent;
use Drupal\Core\Routing\RoutingEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Defines a route subscriber to register a url for serving image styles.
 */
class RouteSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public function onRouteAlter(RouteBuildEvent $event) {
    $event->getRouteCollection()
      ->get('image.style_public')
      // ->setDefault('_controller', 'Drupal\neo_image\Controller\NeoImageController::deliver')
      ->setOption('parameters', [
        'image_style' => [
          'type' => 'image_style_dynamic',
        ],
      ]);
    $event->getRouteCollection()
      ->get('image.style_private')
      // ->setDefault('_controller', 'Drupal\neo_image\Controller\NeoImageController::deliver')
      ->setOption('parameters', [
        'image_style' => [
          'type' => 'image_style_dynamic',
        ],
      ]);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[RoutingEvents::ALTER][] = ['onRouteAlter'];
    return $events;
  }

}
