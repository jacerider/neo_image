<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\neo_image\Plugin\Field\FieldFormatter\NeoImageBaseFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A subclass whose factory was written against the eight-argument signature.
 *
 * This is the caller no grep can find. A formatter base is extended by
 * convention, a subclass supplies its own `create()`, and that factory calls
 * `new static()` with the argument list it was written against. The count is
 * fixed at the subclass's compile time and cannot be corrected from the base,
 * so a required ninth argument is an `ArgumentCountError` on somebody else's
 * site the moment a page renders.
 *
 * A mock cannot reproduce that: the failure is `new static()` in a factory this
 * package does not own, so the fixture is an actual subclass that really does
 * pass eight arguments.
 *
 * It is a helper beside a test rather than a test, which is the established
 * shape in this module's suite.
 */
final class EightArgumentFactoryFormatter extends NeoImageBaseFormatter {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('renderer')
    );
  }

}
