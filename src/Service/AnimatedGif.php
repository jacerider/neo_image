<?php

declare(strict_types=1);

namespace Drupal\neo_image\Service;

use Drupal\Component\Utility\Bytes;
use Drupal\file\FileInterface;

/**
 * Default animated gif service.
 */
class AnimatedGif implements AnimatedGifInterface {

  /**
   * {@inheritdoc}
   */
  public function isFileAnAnimatedGif(FileInterface $file): bool {
    if ($file->getMimeType() != 'image/gif') {
      return FALSE;
    }

    $file_uri = $file->getFileUri();
    if (!\is_string($file_uri)) {
      return FALSE;
    }

    return $this->isAnAnimatedGif($file_uri);
  }

  /**
   * {@inheritdoc}
   *
   * @SuppressWarnings("PHPMD.ErrorControlOperator")
   */
  public function isAnAnimatedGif(string $fileUri): bool {
    if (!\file_exists($fileUri)) {
      return FALSE;
    }

    $fopen = \fopen($fileUri, 'rb');
    if (!$fopen) {
      return FALSE;
    }
    $count = 0;
    // An animated gif contains multiple "frames", with each frame having a
    // header made up of:
    // * a static 4-byte sequence (\x00\x21\xF9\x04)
    // * 4 variable bytes
    // * a static 2-byte sequence (\x00\x2C)
    // We read through the file til we reach the end of the file, or we've found
    // at least 2 frame headers.
    while (!\feof($fopen) && $count < static::MINIMUM_NUMBER_OF_ANIMATED_FRAMES) {
      // Read 100kb at a time.
      $chunk = \fread($fopen, Bytes::KILOBYTE * (int) 100);
      if (!$chunk) {
        break;
      }
      $count += \preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', (string) $chunk);
    }

    \fclose($fopen);
    return $count > 1;
  }

}
