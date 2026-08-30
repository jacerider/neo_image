<?php

namespace Drupal\neo_image;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\RenderableInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * The dynamic image generator.
 */
final class NeoImage implements RenderableInterface {

  /**
   * The URI.
   *
   * @var string
   */
  protected string $uri;

  /**
   * The alt text.
   *
   * @var string|null
   */
  protected string|null $alt;

  /**
   * The title.
   *
   * @var string|null
   */
  protected string|null $title;

  /**
   * The subject cacheability this image was built from.
   *
   * The factory is the only place the subject entity is in scope and the
   * render array is built somewhere else entirely, so this carries the
   * declaration between them. It holds the metadata and not the entity: an
   * entity kept here would invite a second resolution later and give a render
   * object a lifetime it should not have.
   *
   * An image built from a URI string leaves it empty, because a string names
   * no entity and there is nothing to invalidate on.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected CacheableMetadata $cacheability;

  /**
   * An array of NeoImageStyles.
   *
   * @var \Drupal\neo_image\NeoImageStyle[]
   */
  protected array $styles = [
    'sm' => NULL,
    'md' => NULL,
    'lg' => NULL,
    'xl' => NULL,
    '2xl' => NULL,
  ];

  /**
   * Hardcoded breakpoints.
   *
   * @var array
   */
  protected static array $breakpoints = [
    'sm' => [
      'label' => 'Default',
      'mediaQuery' => 'all',
    ],
    'md' => [
      'label' => 'Medium',
      'mediaQuery' => '(width >= 48rem)',
    ],
    'lg' => [
      'label' => 'Large',
      'mediaQuery' => '(width >= 64rem)',
    ],
    'xl' => [
      'label' => 'Extra Large',
      'mediaQuery' => '(width >= 80rem)',
    ],
    '2xl' => [
      'label' => '2x Large',
      'mediaQuery' => '(width >= 96rem)',
    ],
  ];

  /**
   * Constructs a new Image object.
   */
  public function __construct(string $uri, $alt = NULL, $title = NULL) {
    $this->uri = $uri;
    $this->alt = $alt;
    $this->title = $title;
    $this->cacheability = new CacheableMetadata();
    $this->styles['sm'] = new NeoImageStyle();
  }

  /**
   * Creates a new Image object from a media entity.
   *
   * The **failure contract** of this **entry point** is a throw, and it is a
   * throw because a factory must answer an object and has none to answer. A
   * caller that would rather branch than catch asks
   * `NeoImageUtility::resolvedFile()` first: it answers the same question this
   * builds on and never throws.
   *
   * It throws for exactly one reason — the **resolved file** step answered
   * nothing. It used to throw for a second, a missing source field item
   * reported as a missing file, which was a message about a lookup this method
   * never performed; a media with an empty source field and a valid thumbnail
   * renders that thumbnail instead.
   *
   * The object it answers carries the **subject cacheability** it was built
   * from, which `toRenderable()` applies to the array it builds. That is the
   * same declaration the **single-style render** makes for the same subject,
   * so the two shapes cannot disagree about what a render of it depends on.
   *
   * @param \Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The media entity.
   * @param string|null $alt
   *   The alt text.
   * @param string|null $title
   *   The title.
   *
   * @return $this
   *
   * @throws \InvalidArgumentException
   *   When the entity resolves to no file.
   */
  public static function createFromEntity(MediaInterface|FileInterface $entity, $alt = NULL, $title = NULL): static {
    // The authored alt and title come from the one derivation both renders
    // share, and are read from the subject itself rather than from the file it
    // resolves to. An empty supplied value counts as unsupplied: every Twig
    // entry point defaults its alt argument to the empty string, so a fallback
    // that only caught NULL would be inert on exactly those paths. Where the
    // entity authored nothing there is nothing to fall back to, so the
    // supplied value survives as itself.
    $authored = NeoImageUtility::authoredAltAndTitle($entity);
    if ($alt === NULL || $alt === '') {
      $alt = $authored['alt'] ?? $alt;
    }
    if ($title === NULL || $title === '') {
      $title = $authored['title'] ?? $title;
    }
    $file = NeoImageUtility::resolvedFile($entity);
    if (!$file) {
      throw new \InvalidArgumentException('The entity does not resolve to a file.');
    }
    // Seeded after the guard and from the subject rather than the file: the
    // alt and title come from the subject, the URI from the file, and the two
    // go stale independently. The derivation never refuses, so it could be
    // read earlier — but there is no object to carry it on until here, and the
    // failure above answers nothing at all.
    $image = new static($file->getFileUri(), $alt, $title);
    $image->cacheability = NeoImageUtility::subjectCacheability($entity);
    return $image;
  }

  /**
   * Get uri.
   *
   * @return string
   *   The uri.
   */
  public function getUri():string {
    return $this->uri;
  }

  /**
   * Retrieves the breakpoints for the NeoImage class.
   *
   * @return array
   *   The array of breakpoints.
   */
  public static function getBreakpoints():array {
    return self::$breakpoints;
  }

  /**
   * Get media query for size.
   *
   * @return array
   *   The media query.
   */
  public function getMediaQuery($size):string {
    return $this->getBreakpoints()[$size]['mediaQuery'];
  }

  /**
   * Get styles.
   *
   * @return \Drupal\neo_image\NeoImageStyle[]
   *   The styles.
   */
  public function getStyles():array {
    return array_filter($this->styles);
  }

  /**
   * Sets the image styles based on the given dimensions.
   *
   * @param array $dimensions
   *   An array of dimensions for different sizes. Each dimension should include
   *   a 'width', and/or 'height' key. Optionally, it can include 'exact' if
   *   the image should be resized to those exact dimensions, 'achor' for
   *   specifying the anchor point, and 'op' for the operation to perform on the
   *   image (e.g., 'resize', 'crop', etc.).
   *
   * @return $this
   *   The current instance of NeoImage.
   */
  public function autoFromDimensions(array $dimensions):self {
    $dimensions = array_intersect_key($dimensions, self::getBreakpoints());
    foreach ($dimensions as $size => $settings) {
      if (!is_array($settings)) {
        continue;
      }
      $settings += [
        'width' => '',
        'height' => '',
        'exact' => FALSE,
        'bg' => NULL,
        'achor' => '',
        'op' => 'auto',
      ];
      $style = $this->getStyle($size);
      if (method_exists($style, $settings['op'])) {
        $refObj = new \ReflectionObject($style);
        $method = $refObj->getMethod($settings['op']);
        $params = [];
        foreach ($method->getParameters() as $param) {
          $name = $param->getName();
          if (isset($settings[$name])) {
            $params[$name] = $settings[$name];
          }
        }
        if (empty(array_filter($params))) {
          $this->clearStyle($size);
          continue;
        }
        $style->{$settings['op']}(...$params);
      }
    }
    return $this;
  }

  /**
   * Generates a summary of the image dimensions.
   *
   * @param array $dimensions
   *   An array of dimensions for different sizes. Each dimension should include
   *   a 'width', and/or 'height' key.
   *
   * @return array
   *   The summary.
   */
  public static function summaryFromDimensions(array $dimensions):array {
    $summary = [];
    $dimensions = array_intersect_key($dimensions, self::getBreakpoints());
    foreach ($dimensions as $size => $settings) {
      if (!is_array($settings)) {
        continue;
      }
      $settings += [
        'width' => '',
        'height' => '',
        'exact' => FALSE,
        'bg' => NULL,
      ];
      if (empty(array_filter($settings))) {
        continue;
      }
      $sizeLabel = self::getBreakpoints()[$size]['label'];
      if ($settings['width'] && $settings['height']) {
        $summary[] = t('@size: @widthx@height', [
          '@size' => $sizeLabel,
          '@width' => $settings['width'],
          '@height' => $settings['height'],
        ]);
      }
      elseif ($settings['width']) {
        $summary[] = t('@size: @widthw', [
          '@size' => $sizeLabel,
          '@width' => $settings['width'],
        ]);
      }
      elseif ($settings['height']) {
        $summary[] = t('@size: @heighth', [
          '@size' => $sizeLabel,
          '@height' => $settings['height'],
        ]);
      }
      if ($settings['exact']) {
        $summary[count($summary) - 1] .= ' (exact)';
      }
      if ($settings['bg']) {
        $summary[count($summary) - 1] .= ' (bg: #' . $settings['bg'] . ')';
      }
    }
    return $summary;
  }

  /**
   * Get style.
   *
   * @param string $size
   *   The size.
   *
   * @return \Drupal\neo_image\NeoImageStyle
   *   The style.
   */
  public function getStyle($size) {
    if (!array_key_exists($size, $this->styles)) {
      throw new \InvalidArgumentException('Invalid size ' . $size . '.');
    }
    $this->styles[$size] = $this->styles[$size] ?? new NeoImageStyle();
    return $this->styles[$size];
  }

  /**
   * Clears the style for a specific size.
   *
   * @param string $size
   *   The size of the style to clear.
   *
   * @return $this
   */
  public function clearStyle($size):self {
    $this->styles[$size] = NULL;
    return $this;
  }

  /**
   * Retrieves the 'small' NeoImageStyle for the NeoImage.
   *
   * @return NeoImageStyle
   *   The 'sm' NeoImageStyle.
   */
  public function getSm():NeoImageStyle {
    return $this->getStyle('sm');
  }

  /**
   * Retrieves the 'medium' NeoImageStyle for the NeoImage.
   *
   * @return NeoImageStyle
   *   The 'md' NeoImageStyle.
   */
  public function getMd():NeoImageStyle {
    return $this->getStyle('md');
  }

  /**
   * Retrieves the 'large' NeoImageStyle for the NeoImage.
   *
   * @return NeoImageStyle
   *   The 'lg' NeoImageStyle.
   */
  public function getLg():NeoImageStyle {
    return $this->getStyle('lg');
  }

  /**
   * Retrieves the 'xlarge' NeoImageStyle for the NeoImage.
   *
   * @return NeoImageStyle
   *   The 'xl' NeoImageStyle.
   */
  public function getXl():NeoImageStyle {
    return $this->getStyle('xl');
  }

  /**
   * Retrieves the '2xlarge' NeoImageStyle for the NeoImage.
   *
   * @return NeoImageStyle
   *   The '2xl' NeoImageStyle.
   */
  public function get2Xl():NeoImageStyle {
    return $this->getStyle('2xl');
  }

  /**
   * {@inheritDoc}
   *
   * @param string|null $alt
   *   The alt text.
   * @param string|null $title
   *   The title.
   * @param array $attributes
   *   The attributes.
   *
   * @return array
   *   The renderable array, carrying the **subject cacheability** the image
   *   was built from, so a caller need not declare a dependency the render
   *   already took. An image built from a URI string carries none.
   */
  public function toRenderable($alt = NULL, $title = NULL, $attributes = []):array {
    // The same rule as createFromEntity(): an empty supplied value means the
    // caller supplied nothing and falls back to what this image was built
    // with. Twig's `neo_image(media)` reaches here with the empty string its
    // own argument defaults to, so without this the alt derived a moment ago
    // would be overwritten by that default before it ever reached the image.
    if ($alt === NULL || $alt === '') {
      $alt = $this->alt ?? $alt;
    }
    if ($title === NULL || $title === '') {
      $title = $this->title ?? $title;
    }
    $build = [
      '#theme' => 'neo_image',
      '#neoImage' => $this,
      '#alt' => $alt,
      '#title' => $title,
      '#attributes' => $attributes,
    ];
    // Applied rather than branched on: a URI-built image carries an empty
    // metadata, and an empty one applies as no tags rather than as a special
    // case. Tags without `#cache[keys]` create no cache entry, so this costs
    // nothing per render and changes no markup.
    $this->cacheability->applyTo($build);
    return $build;
  }

}
