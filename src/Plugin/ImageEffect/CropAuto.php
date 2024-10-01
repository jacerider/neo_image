<?php

declare(strict_types=1);

namespace Drupal\neo_image\Plugin\ImageEffect;

use Drupal\Core\Image\ImageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\image\Attribute\ImageEffect;
use Drupal\image\ImageEffectBase;

/**
 * Adjust image transparency.
 */
#[ImageEffect(
  id: 'image_crop_sides',
  label: new TranslatableMarkup('Crop: Sides'),
  description: new TranslatableMarkup('Will automatically remove empty transparency around the image.'),
)]
class CropAuto extends ImageEffectBase {

  /**
   * {@inheritdoc}
   */
  public function applyEffect(ImageInterface $image) {
    return $image->apply('crop_sides');
  }

}
