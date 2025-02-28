<?php

namespace Drupal\neo_image\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Plugin implementation of the 'neo_image_image' formatter.
 */
#[FieldFormatter(
  id: 'neo_image_image',
  label: new TranslatableMarkup('Neo | Image'),
  field_types: [
    'image',
  ]
)]
final class NeoImageImageFormatter extends NeoImageBaseFormatter {

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    return ($field_definition->getFieldStorageDefinition()->getSetting('target_type') == 'file');
  }

}
