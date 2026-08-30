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
   * The styles, parsed from the names the **style scan** listed.
   *
   * @var \Drupal\neo_image\NeoImageStyle[]
   */
  protected array $styles;

  /**
   * The **style scan**: every neo directory name on disk, listed once.
   *
   * The manager keeps no stored registry — the **derivative directories** are
   * the registry — so this listing is it, and both of the manager's answers
   * come out of it. It is memoised for the request rather than cached, because
   * it changes whenever any request writes a derivative for a size that did not
   * exist before; invalidating a persistent copy would mean hooking core's
   * derivative-writing path, and the failure when it went stale would be a
   * style missing from an admin select or a flush that skipped a directory.
   *
   * @var string[]
   */
  protected array $styleNames;

  /**
   * One **directory style** per name the **style scan** listed, keyed by name.
   *
   * Memoised beside the other two, and for the same reason: it is derived from
   * a directory listing taken once per request, so it needs no invalidation.
   * The memo is not decoration. Core invokes the **per-file flush** once per
   * *configured* image style, so one file move runs it once per configured
   * style, and an unmemoised list would rebuild an unsaved config entity per
   * **derivative directory** on every one of them.
   *
   * @var \Drupal\image\ImageStyleInterface[]
   */
  protected array $directoryStyles;

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
   * here and took every caller with it.
   *
   * The callers left are the two where somebody is looking at a form — the
   * image settings form and a field formatter's settings — which is also who
   * the warning below is written for. The **per-file flush** is no longer among
   * them: it iterates `getDirectoryStyles()`, whose members are built from the
   * **style scan**'s names rather than parsed from them, so it reaches the
   * directories this reader skips. See ADR 0015.
   *
   * The styles are parsed from the **style scan**'s names rather than from a
   * reading of their own, so a caller asking for styles and a caller asking for
   * names cost one directory read between them however they are ordered.
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
   * This is the **style scan** itself, and `getStyles()` is one of its readers:
   * the listing happens here, once per request, and the parsed styles are
   * derived from what it answers.
   *
   * @return string[]
   *   The directory names, deduplicated across stream wrappers.
   */
  public function getStyleNames(): array {
    if (!isset($this->styleNames)) {
      $names = [];
      $mask = "/^neo-/";
      $wrappers = $this->streamWrapperManager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE);
      foreach ($wrappers as $wrapper => $wrapper_data) {
        if (file_exists($stylesDir = $wrapper . '://styles')) {
          // `scandir()` rather than an `opendir()` handle: the handle was never
          // closed, so every listing left an open stream resource for the rest
          // of the request. Reading the directory into an array works over
          // stream wrappers just as well and removes the resource entirely,
          // rather than adding a `closedir()` obligation the next early return
          // added to this loop can skip.
          //
          // SCANDIR_SORT_NONE because this is a mechanical swap and the order
          // is observable: it is the order of the size select on the image
          // settings form. Sorting is what `scandir()` adds over `readdir()`
          // and it is the one thing here that would change an answer, so it is
          // turned off — the entries come back in the order the directory
          // yields them, exactly as before.
          foreach (@scandir($stylesDir, SCANDIR_SORT_NONE) ?: [] as $filename) {
            if (preg_match($mask, $filename)) {
              $names[$filename] = $filename;
            }
          }
        }
      }
      $this->styleNames = array_values($names);
    }
    return $this->styleNames;
  }

  /**
   * Get one **directory style** per **derivative directory** on disk.
   *
   * A directory style carries a directory's name and the **format conversion**
   * and no other effect, which is all core's `buildUri()` reads off a style. It
   * is what addresses a derivative *inside* a directory, where the **style
   * flush** needs only the directory itself.
   *
   * The list is **uniform**: every name the **style scan** listed gets one,
   * whether or not the **codec** can parse it. That is the difference between
   * this and `getStyles()`, and it is the point — a directory holding a
   * **rejected id** is still a directory this module wrote, so what runs on
   * every file move must be able to address it. A parsed-where-possible list
   * would put the codec, its throw and its warning back on that path to produce
   * a list whose two halves behave identically anyway. See ADR 0015.
   *
   * Memoised for the request, and derived from the **style scan**'s names, so a
   * caller asking for styles and a caller asking for directory styles cost one
   * directory read between them however they are ordered.
   *
   * @return \Drupal\image\ImageStyleInterface[]
   *   The directory styles, keyed by the directory name each carries.
   */
  public function getDirectoryStyles(): array {
    if (!isset($this->directoryStyles)) {
      $this->directoryStyles = [];
      foreach ($this->getStyleNames() as $filename) {
        $this->directoryStyles[$filename] = NeoImageStyle::buildDirectoryStyle($filename);
      }
    }
    return $this->directoryStyles;
  }

  /**
   * Delete a style.
   *
   * The convenient call for one name, and the one most callers already make.
   * It is not deprecated and its signature is untouched: it delegates to the
   * list form with a single element, which is one honest line.
   *
   * It is narrowed, though, because a delegated guard is still a guard. The
   * name has to be a **flushable name** — the `neo-` prefix and a single path
   * segment, no `/` and no `\` — or this throws and deletes nothing. A caller
   * passing a configured image style's name, `large` among them, is refused
   * where it used to delete a directory; that directory is core's
   * `ImageStyle::flush()` to remove, not this module's.
   *
   * @param string $style_name
   *   The style name.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the name is not a **flushable name**.
   */
  public function flushStyle(string $style_name): self {
    return $this->flushStyles([$style_name]);
  }

  /**
   * Delete every named style.
   *
   * The **style flush** over a list, which is what the image settings form's
   * flush button hands it. The writable-wrapper list is built once for the
   * whole flush rather than once per name: flushing a site's **derivative
   * directories** through the single-name form rebuilt that list once per
   * directory.
   *
   * Every name has to be a **flushable name**: the `neo-` prefix, and a single
   * path segment — no `/`, no `\`. The prefix leaves no room for a scheme and
   * the one segment leaves no room for traversal, which is what makes joining
   * a caller's name onto `{scheme}://styles/` and handing the result to a
   * recursive delete safe. It is exactly what the **style scan** lists, so
   * every name this module produces passes. A name outside that shape throws
   * `\InvalidArgumentException` naming it, and the whole list is required
   * before the writable wrappers are asked for and before the first deletion,
   * so a flush either deletes everything it was asked for or deletes nothing —
   * half a flush is the worse answer, because the caller cannot tell which
   * half happened.
   *
   * That shape is **not** the **id grammar**, and the two must not be
   * conflated. Running the **codec** here would refuse a **rejected id** and
   * lock its directory on disk, which is the set this flush exists to clear;
   * these are names, not parsed styles, so a directory holding an id the codec
   * refuses is still removable — see `getStyleNames()` and ADR 0015. The guard
   * refuses a path escape and a missing prefix and nothing else, which is why
   * both `neo-junk` and `neo-cs~e--w-36_h-36` are flushable names.
   *
   * @param string[] $style_names
   *   The style names.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When any name is not a **flushable name**.
   */
  public function flushStyles(array $style_names): self {
    foreach ($style_names as $style_name) {
      // Written out here rather than as a call into the **codec**, so that a
      // later reader cannot mistake the two: a **rejected id** is a flushable
      // name and its directory has to stay removable.
      $escapes = str_contains($style_name, '/') || str_contains($style_name, '\\');
      if ($escapes || !str_starts_with($style_name, 'neo-')) {
        throw new \InvalidArgumentException(sprintf(
          'The style name "%s" cannot be flushed: it has to carry the "neo-" '
          . 'prefix and be a single path segment, with no "/" and no "\\".',
          $style_name
        ));
      }
    }
    $wrappers = $this->streamWrapperManager->getWrappers(StreamWrapperInterface::WRITE_VISIBLE);
    foreach ($wrappers as $wrapper => $wrapper_data) {
      if (file_exists($stylesDir = $wrapper . '://styles')) {
        foreach ($style_names as $style_name) {
          $style_file = $stylesDir . '/' . $style_name;
          if (file_exists($style_file)) {
            $this->fileSystem->deleteRecursive($style_file);
          }
        }
      }
    }
    return $this;
  }

}
