<?php

declare(strict_types=1);

namespace Drupal\neo_image;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\media\MediaTypeInterface;

/**
 * Image handling methods for neo_image.
 */
abstract class NeoImageUtility {

  /**
   * Resolves the file an image is built from.
   *
   * The single **resolved file** step every **entry point** delegates to.
   * Given a media it answers the media's thumbnail, given a file it answers
   * that file, and where there is neither it answers nothing. Four entry
   * points used to answer this privately and all four answered it the same
   * way; what differed was only how each reported the empty case, so this is
   * an extraction and not a change of resolution.
   *
   * It **never throws**, which is what lets a caller branch rather than catch.
   * The three **failure contracts** live in the entry points, each matching
   * the shape of what that entry point returns; this step has one answer and
   * one absence.
   *
   * It answers **entities, not the filesystem**. "No file" means the entity
   * reference is absent or is not a file — there is no `file_exists`, no
   * readability check and no derivative check, because none of the four entry
   * points ever performed one and a stat per render is a cost nobody asked
   * for.
   *
   * Its parameter stays narrow on purpose. A caller holding some other entity
   * narrows it first; widening this signature would only move that narrowing
   * inside and call the drift fixed.
   *
   * @param \Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The media or file entity to resolve.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file the image is built from, or NULL where there is none.
   */
  public static function resolvedFile(MediaInterface|FileInterface $entity): ?FileInterface {
    if (!$entity instanceof MediaInterface) {
      return $entity;
    }
    $file = $entity->get('thumbnail')->entity;
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Reads the alt and title an editor authored on an entity's image.
   *
   * The single derivation both renders use. Both properties come from the same
   * field item — the one the media's source plugin declares — so the two halves
   * of one answer cannot come from two different places, which is the shape of
   * the defect this repairs. Core's `thumbnail_alt_value` metadata attribute is
   * deliberately not used: it covers alt and has no title counterpart.
   *
   * Three subjects answer nothing, and none of them is an error: a bare file
   * entity, which has no authored alt; a media whose source field is not an
   * image field, which carries neither property; and an image media whose alt
   * was left blank. Nothing is invented in their place — not a filename, not a
   * media label. This resolves no file and throws nothing.
   *
   * @param \Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The media or file entity to read.
   *
   * @return array
   *   An associative array with an 'alt' and a 'title' key, each holding the
   *   authored value, or NULL where nothing was authored.
   */
  public static function authoredAltAndTitle(MediaInterface|FileInterface $entity): array {
    $authored = [
      'alt' => NULL,
      'title' => NULL,
    ];
    if (!$entity instanceof MediaInterface) {
      return $authored;
    }
    $type = $entity->bundle->entity;
    if (!$type instanceof MediaTypeInterface) {
      return $authored;
    }
    $fieldDefinition = $entity->getSource()->getSourceFieldDefinition($type);
    if (!$fieldDefinition) {
      return $authored;
    }
    $item = $entity->get($fieldDefinition->getName())->first();
    if (!$item) {
      return $authored;
    }
    // Read the raw value rather than the properties: a source field that is not
    // an image field has no 'alt' and no 'title' to ask for, and asking anyway
    // is how a tolerated case becomes a thrown one.
    $value = $item->getValue();
    foreach (array_keys($authored) as $property) {
      if (isset($value[$property]) && $value[$property] !== '') {
        $authored[$property] = $value[$property];
      }
    }
    return $authored;
  }

  /**
   * Derives the cache metadata a render of a subject entity depends on.
   *
   * The **subject cacheability**: the subject entity's own cacheability merged
   * with its **resolved file**'s. Both halves are read per render — the
   * authored alt and title come from the subject, the URI comes from the file
   * — and the two go stale independently, so declaring the subject alone
   * leaves a file replaced behind an unsaved media serving a stale render.
   * That is precisely the half a hand-written declaration in a caller gets
   * wrong.
   *
   * It takes the subject alone and resolves the file itself, so it can never
   * be handed a file belonging to a different subject, and it matches the two
   * derivations beside it: one subject in, one answer out. The resolution is a
   * property read on an entity the caller has just loaded, not a query.
   *
   * It **never refuses**, in keeping with `resolvedFile()`'s rule: a subject
   * that resolves to no file answers the subject's own metadata alone, so a
   * caller may derive this before knowing whether a render will happen.
   *
   * Nothing is added beyond the declaration — no cache contexts, no max-age of
   * this module's invention, no access result. Neither render **entry point**
   * checks `view` access, and adding one here would be a behaviour change
   * wearing a caching change's clothes.
   *
   * @param \Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The media or file entity a render is built from.
   *
   * @return \Drupal\Core\Cache\CacheableMetadata
   *   The metadata the render depends on. Applied to a render array with
   *   `applyTo()`, or merged into another element with
   *   `addCacheableDependency()`.
   */
  public static function subjectCacheability(MediaInterface|FileInterface $entity): CacheableMetadata {
    $cacheability = CacheableMetadata::createFromObject($entity);
    $file = static::resolvedFile($entity);
    if ($file) {
      $cacheability = $cacheability->merge(CacheableMetadata::createFromObject($file));
    }
    return $cacheability;
  }

  /**
   * Computes a length based on a length specification and an actual length.
   *
   * Examples:
   *  (50, 400) returns 50; (50%, 400) returns 200;
   *  (50, null) returns 50; (50%, null) returns null;
   *  (null, null) returns null; (null, 100) returns null.
   *
   * @param string|int|null $length_specification
   *   The length specification. An integer value or a % specification.
   * @param int|null $current_length
   *   The current length. May be null.
   *
   * @return int|null
   *   The computed length.
   */
  public static function percentFilter(string|int|NULL $length_specification, ?int $current_length): ?int {
    if ($length_specification === NULL) {
      return NULL;
    }
    if (strpos((string) $length_specification, '%') !== FALSE) {
      if ($current_length === NULL) {
        return NULL;
      }
      return (int) (((float) str_replace('%', '', $length_specification)) * 0.01 * $current_length);
    }
    return (int) $length_specification;
  }

  /**
   * Determines the dimensions of a resized image.
   *
   * Based on the current size and resize specification.
   *
   * @param int|null $source_width
   *   Source image width.
   * @param int|null $source_height
   *   Source image height.
   * @param string|int|null $width_specification
   *   The width specification. An integer value or a % specification.
   * @param string|int|null $height_specification
   *   The height specification. An integer value or a % specification.
   * @param bool $square
   *   (Optional) when TRUE and one of the specifications is NULL, will return
   *   the same value for width and height.
   *
   * @return array
   *   Associative array.
   *   - width: Integer with the resized image width.
   *   - height: Integer with the resized image height.
   */
  public static function resizeDimensions(?int $source_width, ?int $source_height, string|int|NULL $width_specification, string|int|NULL $height_specification, bool $square = FALSE): array {
    $dimensions = [];
    $dimensions['width'] = static::percentFilter($width_specification, $source_width);
    $dimensions['height'] = static::percentFilter($height_specification, $source_height);

    if (is_null($dimensions['width']) && is_null($dimensions['height'])) {
      return $dimensions;
    }

    if (!$dimensions['width'] || !$dimensions['height']) {
      if (is_null($source_width) || is_null($source_height)) {
        $dimensions['width'] = NULL;
        $dimensions['height'] = NULL;
      }
      else {
        if ($square) {
          $aspect_ratio = 1;
        }
        else {
          $aspect_ratio = $source_height / $source_width;
        }
        if ($dimensions['width'] && !$dimensions['height']) {
          $dimensions['height'] = (int) round($dimensions['width'] * $aspect_ratio);
        }
        elseif (!$dimensions['width'] && $dimensions['height']) {
          $dimensions['width'] = (int) round($dimensions['height'] / $aspect_ratio);
        }
      }
    }

    return $dimensions;
  }

  /**
   * Returns the offset in pixels from the anchor.
   *
   * @param string $anchor
   *   The anchor ('top', 'left', 'bottom', 'right', 'center').
   * @param int $current_size
   *   The current size, in pixels.
   * @param int $new_size
   *   The new size, in pixels.
   *
   * @return int
   *   The offset from the anchor, in pixels.
   *
   * @throws \InvalidArgumentException
   *   When the $anchor argument is not valid.
   */
  public static function getKeywordOffset(string $anchor, int $current_size, int $new_size): int {
    switch ($anchor) {
      case 'bottom':
      case 'right':
        return $current_size - $new_size;

      case 'center':
        return (int) round($current_size / 2 - $new_size / 2);

      case 'top':
      case 'left':
        return 0;

    }

    throw new \InvalidArgumentException("Invalid anchor '{$anchor}' provided to getKeywordOffset()");
  }

}
