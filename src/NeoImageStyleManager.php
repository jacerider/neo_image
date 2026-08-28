<?php

declare(strict_types=1);

namespace Drupal\neo_image;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Psr\Log\LoggerInterface;

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
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Get the styles.
   *
   * One style per **derivative directory** on disk whose name the **codec**
   * admits. A directory it refuses is skipped and logged rather than promoted
   * into a style: before that, a single piece of junk on disk threw out of
   * here and took every caller with it — the image settings form, every field
   * formatter's settings, and `hook_image_style_flush`.
   *
   * @return \Drupal\neo_image\NeoImageStyle[]
   *   The styles, keyed by their id.
   */
  public function getStyles(?array $effect_types = NULL): array {
    if (!isset($this->styles)) {
      $this->styles = [];
      foreach ($this->getStyleNames() as $filename) {
        $neoImageStyle = new NeoImageStyle();
        try {
          $neoImageStyle->setParameters($neoImageStyle->convertIdToParams($filename));
        }
        catch (\InvalidArgumentException $e) {
          // A directory the **codec** refuses is site state somebody should
          // know about, not a request: it is skipped rather than promoted into
          // a style, an option on the image settings form and a flush target.
          // Once per request rather than once per caller, because the list
          // this builds is memoised for the request.
          $this->logger->warning('Skipped the image style directory %directory: @reason It can be removed with the "Flush Image Styles" button on the image settings form.', [
            '%directory' => $filename,
            '@reason' => $e->getMessage(),
          ]);
          continue;
        }
        $this->styles[$filename] = $neoImageStyle;
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
   * Get the name of every neo style directory on disk.
   *
   * The names, not the styles. It is what the image settings form's flush
   * button iterates, and the reason skipping a directory the **codec** refuses
   * does not orphan it: a **derivative directory** that is not a style this
   * module can build is still a directory this module wrote, so it stays
   * removable through the admin flush. Without this the change would have
   * closed the door and locked the existing junk inside.
   *
   * @return string[]
   *   The directory names, deduplicated across stream wrappers.
   */
  public function getStyleNames(): array {
    $names = [];
    $wrappers = $this->streamWrapperManager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE);
    foreach ($wrappers as $wrapper => $wrapper_data) {
      if (file_exists($stylesDir = $wrapper . '://styles')) {
        $mask = "/^neo-/";
        if ($handle = @opendir($stylesDir)) {
          while (FALSE !== ($filename = readdir($handle))) {
            if (preg_match($mask, $filename)) {
              $names[$filename] = $filename;
            }
          }
        }
      }
    }
    return array_values($names);
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
