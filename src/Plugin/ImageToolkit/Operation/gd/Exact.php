<?php

declare(strict_types=1);

namespace Drupal\neo_image\Plugin\ImageToolkit\Operation\gd;

use Drupal\Core\ImageToolkit\Attribute\ImageToolkitOperation;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_image\NeoImagePositionedRectangle;
use Drupal\system\Plugin\ImageToolkit\Operation\gd\GDImageToolkitOperationBase;
use Drupal\system\Plugin\ImageToolkit\Operation\gd\Resize;

/**
 * Defines GD2 set canvas operation.
 */
#[ImageToolkitOperation(
  id: 'neo_image_gd_exacty',
  toolkit: 'gd',
  operation: 'exact',
  label: new TranslatableMarkup('Exact'),
  description: new TranslatableMarkup('Lay the image over a colored canvas.'),
)]
class Exact extends Resize {

  /**
   * {@inheritdoc}
   */
  protected function arguments() {
    return [
      'canvas_color' => [
        'description' => 'Color',
        'type' => '?string',
        'required' => FALSE,
        'default' => NULL,
      ],
      'width' => [
        'description' => 'The width of the canvas image, in pixels',
        'type' => 'int',
      ],
      'height' => [
        'description' => 'The height of the canvas image, in pixels',
        'type' => 'int',
      ],
      'x_pos' => [
        'description' => 'The left offset of the original image on the canvas, in pixels',
        'type' => 'int',
      ],
      'y_pos' => [
        'description' => 'The top offset of the original image on the canvas, in pixels',
        'type' => 'int',
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(array $arguments = []) {
    $scale_arguments = [
      'width' => $arguments['scale_width'],
      'height' => $arguments['scale_height'],
      'upscale' => TRUE,
    ];

    $scale_arguments = parent::validateArguments($scale_arguments);

    // kint($scale_arguments, $this->getToolkit()->getWidth());
    // die;
    $status = parent::execute($scale_arguments);
    if (!$status) {
      return FALSE;
    }

    // Store the original image.
    $original_image = $this->getToolkit()->getImage();

    // Prepare the canvas.
    $data = [
      'width' => $arguments['width'],
      'height' => $arguments['height'],
      'extension' => image_type_to_extension($this->getToolkit()->getType(), FALSE),
      'transparent_color' => $this->getToolkit()->getTransparentColor(),
      'is_temp' => TRUE,
    ];
    if (!$this->getToolkit()->apply('create_new', $data)) {
      return FALSE;
    }

    // Fill the canvas with required color.
    $data = [
      'rectangle' => new NeoImagePositionedRectangle($arguments['width'], $arguments['height']),
      'fill_color' => $arguments['canvas_color'],
    ];
    if (!$this->getToolkit()->apply('draw_rectangle', $data)) {
      return FALSE;
    }

    // Overlay the current image on the canvas.
    imagealphablending($original_image, TRUE);
    imagesavealpha($original_image, TRUE);
    imagealphablending($this->getToolkit()->getImage(), TRUE);
    imagesavealpha($this->getToolkit()->getImage(), TRUE);
    if (imagecopy($this->getToolkit()->getImage(), $original_image, $arguments['x_pos'], $arguments['y_pos'], 0, 0, imagesx($original_image), imagesy($original_image))) {
      return TRUE;
    }
    else {
      // In case of failure, restore the original image.
      $this->getToolkit()->setimage($original_image);
    }
    return FALSE;
  }

}
