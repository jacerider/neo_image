<?php

declare(strict_types=1);

namespace Drupal\neo_image;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;

/**
 * Manager for dynamically generated neo image styles.
 */
final class NeoImageStyleManager {

  /**
   * The styles.
   *
   * @var \Drupal\neo_image\NeoImageStyle[]
   */
  protected array $styles;

  /**
   * Constructs a NeoImageStyleManager object.
   */
  public function __construct(
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Get the styles.
   *
   * @return \Drupal\neo_image\NeoImageStyle[]
   *   The styles.
   */
  public function getStyles(array $effect_types = NULL): array {
    if (!isset($this->styles)) {
      $this->styles = [];
      $wrappers = $this->streamWrapperManager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE);
      foreach ($wrappers as $wrapper => $wrapper_data) {
        if (file_exists($stylesDir = $wrapper . '://styles')) {
          $mask = "/^neo-/";
          if ($handle = @opendir($stylesDir)) {
            while (FALSE !== ($filename = readdir($handle))) {
              if (preg_match($mask, $filename)) {
                $neoImageStyle = new NeoImageStyle();
                $neoImageStyle->setParameters($neoImageStyle->convertIdToParams($filename));
                $this->styles[$filename] = $neoImageStyle;
              }
            }
          }
        }
      }
    }
    if ($effect_types) {
      return array_filter($this->styles, function (NeoImageStyle $style) use ($effect_types) {
        return $style->hasEffectTypes($effect_types);
      });
    }
    return $this->styles;
  }

  /**
   * Delete a style.
   *
   * @param string $style_name
   *   The style name.
   *
   * @return $this
   */
  public function flushStyle(string $style_name): self {
    $wrappers = $this->streamWrapperManager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE);
    foreach ($wrappers as $wrapper => $wrapper_data) {
      if (file_exists($stylesDir = $wrapper . '://styles')) {
        $style_file = $stylesDir . '/' . $style_name;
        if (file_exists($style_file)) {
          $this->fileSystem->deleteRecursive($style_file);
        }
      }
    }
    return $this;
  }

}
