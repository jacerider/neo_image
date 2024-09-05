<?php

namespace Drupal\neo_image;

use Drupal\Core\Render\RenderableInterface;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;

/**
 * The dynamic image generator.
 */
final class NeoImage implements RenderableInterface {

  /**
   * The file.
   *
   * @var \Drupal\file\FileInterface
   */
  protected FileInterface $file;

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
      'mediaQuery' => '(min-width: 640px)',
      // 'mediaQuery' => '(min-width: 768px)',
    ],
    'lg' => [
      'label' => 'Large',
      'mediaQuery' => '(min-width: 768px)',
      // 'mediaQuery' => '(min-width: 1024px)',
    ],
    'xl' => [
      'label' => 'Extra Large',
      'mediaQuery' => '(min-width: 1024px)',
      // 'mediaQuery' => '(min-width: 1280px)',
    ],
    '2xl' => [
      'label' => '2x Large',
      'mediaQuery' => '(min-width: 1280px)',
      // 'mediaQuery' => '(min-width: 1536px)',
    ],
  ];

  /**
   * Constructs a new Image object.
   */
  public function __construct(FileInterface $file, $alt = NULL, $title = NULL) {
    $this->file = $file;
    $this->alt = $alt;
    $this->title = $title;
    $this->styles['sm'] = new NeoImageStyle();
  }

  /**
   * Creates a new Image object from a media entity.
   *
   * @param \Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The media entity.
   * @param string|null $alt
   *   The alt text.
   * @param string|null $title
   *   The title.
   *
   * @return $this
   */
  public static function createFromEntity(MediaInterface|FileInterface $entity, $alt = NULL, $title = NULL): static {
    if ($entity instanceof MediaInterface) {
      /** @var \Drupal\media\MediaInterface $entity */
      $fieldDefinition = $entity->getSource()->getSourceFieldDefinition($entity->bundle->entity);
      $value = $entity->get($fieldDefinition->getName())->first()->getValue() + [
        'alt' => '',
        'title' => '',
      ];
      $alt = $alt ?? $value['title'] ?: NULL;
      $title = $title ?? $value['title'] ?: NULL;
      $entity = $entity->get('thumbnail')->entity;
      if (!$entity) {
        throw new \InvalidArgumentException('The media entity does not have a thumbnail.');
      }
    }
    return new static($entity, $alt, $title);
  }

  /**
   * Get file.
   *
   * @return \Drupal\file\FileInterface
   *   The file.
   */
  public function getFile():FileInterface {
    return $this->file;
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
   *   a 'width', and/or 'height' key.
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
      ];
      if (empty(array_filter($settings))) {
        continue;
      }
      $style = $this->getStyle($size);
      $style->auto($settings['width'], $settings['height']);
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
   *
   * @return array
   *   The renderable array.
   */
  public function toRenderable($alt = NULL, $title = NULL):array {
    return [
      '#theme' => 'neo_image',
      '#neoImage' => $this,
      '#title' => $alt ?? $this->title,
      '#alt' => $title ?? $this->alt,
    ];
  }

}
