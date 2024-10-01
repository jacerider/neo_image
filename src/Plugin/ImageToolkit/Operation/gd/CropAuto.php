<?php

declare(strict_types=1);

namespace Drupal\neo_image\Plugin\ImageToolkit\Operation\gd;

use Drupal\Core\ImageToolkit\Attribute\ImageToolkitOperation;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\system\Plugin\ImageToolkit\Operation\gd\GDImageToolkitOperationBase;

/**
 * Defines GD2 Crop Auto operation.
 */
#[ImageToolkitOperation(
  id: 'neo_image_crop_sides',
  toolkit: 'gd',
  operation: 'crop_sides',
  label: new TranslatableMarkup('Crop Sides'),
  description: new TranslatableMarkup('Automatically remove empty transparency around the image.'),
)]
class CropAuto extends GDImageToolkitOperationBase {

  /**
   * {@inheritdoc}
   */
  protected function arguments() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(array $arguments) {
    // PHP installations using non-bundled GD do not have imagecropauto.
    if (!function_exists('imagecropauto')) {
      $this->logger->notice('The image %file could not be rotated because the imagecropauto() function is not available in this PHP installation.', ['%file' => $this->getToolkit()->getSource()]);
      return FALSE;
    }

    $original_image = $this->getToolkit()->getImage();
    if ($new_image = imagecropauto($original_image, IMG_CROP_SIDES)) {
      $this->getToolkit()->setImage($new_image);
      return TRUE;
    }
    return FALSE;
  }

}
