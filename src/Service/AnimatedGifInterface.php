<?php

declare(strict_types=1);

namespace Drupal\neo_image\Service;

use Drupal\file\FileInterface;

/**
 * Interface for animated gif service.
 */
interface AnimatedGifInterface {

  /**
   * The minimum number of animated frames required.
   */
  public const int MINIMUM_NUMBER_OF_ANIMATED_FRAMES = 2;

  /**
   * Check if a file entity is an animated Gif.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file variable to check.
   *
   * @return bool
   *   Return true if the file is animated.
   */
  public function isFileAnAnimatedGif(FileInterface $file): bool;

  /**
   * Check if a gif image is animated.
   *
   * @param string $fileUri
   *   The uri file.
   *
   * @return bool
   *   Return true if the file contains multiple "frames".
   */
  public function isAnAnimatedGif(string $fileUri): bool;

}
