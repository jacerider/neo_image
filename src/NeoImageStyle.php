<?php

namespace Drupal\neo_image;

use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;

/**
 * The dynamic image generator.
 */
class NeoImageStyle {

  /**
   * The parameters.
   *
   * @var array
   */
  protected array $parameters = [];

  /**
   * The conversion keys.
   *
   * @var array
   */
  protected array $effectKeys = [
    'r' => 'image_resize',
    's' => 'image_scale',
    'c' => 'image_crop',
    'sc' => 'image_scale_and_crop',
    'f' => 'focal_point_scale_and_crop',
    'fw' => 'focal_point_crop_by_width',
  ];

  /**
   * The conversion labels.
   *
   * @var array
   */
  protected array $effectLabels = [
    'r' => 'Resize',
    's' => 'Scale',
    'c' => 'Crop',
    'sc' => 'Scale and Crop',
    'f' => 'Focal Scale and Crop',
    'fw' => 'Focal Scale by Width',
  ];

  /**
   * The property keys.
   *
   * @var array
   */
  protected array $propertyKeys = [
    'w' => 'width',
    'h' => 'height',
    'a' => 'anchor',
  ];

  /**
   * The anchor keys.
   *
   * @var array
   */
  protected array $valueKeys = [
    'a' => [
      'lt' => 'left-top',
      'ct' => 'center-top',
      'rt' => 'right-top',
      'l' => 'left-center',
      'c' => 'center-center',
      'r' => 'right-center',
      'lb' => 'left-bottom',
      'cb' => 'center-bottom',
      'rb' => 'right-bottom',
    ],
  ];

  /**
   * Get image style URL for a media entity.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media object containing the thumbnail file.
   *
   * @return string
   *   The URL of the media entity.
   *
   * @throws \InvalidArgumentException
   *   If the media entity does not have a thumbnail file.
   */
  public function buildUrlForMedia(MediaInterface $media):string {
    $file = $media->get('thumbnail')->entity;
    if ($file instanceof FileInterface) {
      return $this->getImageStyle()->buildUrl($file->getFileUri());
    }
    throw new \InvalidArgumentException('The media entity does not have a thumbnail file.');
  }

  /**
   * Returns the label for the NeoImageStyle.
   *
   * This method generates a label for the NeoImageStyle by iterating through
   * the parameters and constructing a label string based on the effect and its
   * corresponding configuration. The label includes the effect label and any
   * effect properties and values.
   *
   * @return string
   *   The label for the NeoImageStyle.
   */
  public function label() {
    $label = [];
    foreach ($this->getParameters() as $effect => $config) {
      $effectLabel = [];
      foreach ($config as $property => $value) {
        $effectLabel[] = $this->propertyKeys[$property] . ': ' . ($this->valueKeys[$property][$value] ?? $value);
      }
      $label[] = $this->effectLabels[$effect] . ($effectLabel ? ' (' . implode(' | ', $effectLabel) . ')' : '');
    }
    return implode(' ', $label);
  }

  /**
   * Size using the best effect.
   *
   * @param string|int $width
   *   The width.
   * @param string|int $height
   *   The height.
   *
   * @return $this
   */
  public function auto($width = NULL, $height = NULL):self {
    if (!$width && !$height) {
      throw new \InvalidArgumentException('Width or height must be set.');
    }
    if ($width && $height) {
      $this->focal($width, $height);
    }
    else {
      $this->scale($width, $height);
    }
    return $this;
  }

  /**
   * Set size.
   *
   * @param string|int $width
   *   The width.
   * @param string|int $height
   *   The height.
   *
   * @return $this
   */
  public function size($width, $height):self {
    $this->parameters['r']['w'] = (int) $width;
    $this->parameters['r']['h'] = (int) $height;
    return $this;
  }

  /**
   * Set scale.
   *
   * @param string|int $width
   *   The width.
   * @param string|int $height
   *   The height.
   *
   * @return $this
   */
  public function scale($width = NULL, $height = NULL):self {
    if (!$width && !$height) {
      throw new \InvalidArgumentException('Width or height must be set.');
    }
    if ($width) {
      $this->parameters['s']['w'] = (int) $width;
    }
    if ($height) {
      $this->parameters['s']['h'] = (int) $height;
    }
    return $this;
  }

  /**
   * Set crop.
   *
   * @param string|int $width
   *   The width.
   * @param string|int $height
   *   The height.
   * @param string $anchor
   *   The anchor.
   *   Options:
   *     left-top
   *     center-top
   *     right-top
   *     left-center,
   *     center-center
   *     right-center
   *     left-bottom
   *     center-bottom
   *     right-bottom.
   *
   * @return $this
   */
  public function crop($width, $height, $anchor = 'center-center'):self {
    $anchorKeys = array_flip($this->valueKeys['a']);
    if (!isset($anchorKeys[$anchor])) {
      throw new \InvalidArgumentException('Invalid anchor value.');
    }
    $this->parameters['c']['w'] = (int) $width;
    $this->parameters['c']['h'] = (int) $height;
    $this->parameters['c']['a'] = $anchorKeys[$anchor];
    return $this;
  }

  /**
   * Set focal point scale and crop.
   *
   * @param string|int $width
   *   The width.
   * @param string|int $height
   *   The height.
   *
   * @return $this
   */
  public function focal($width, $height):self {
    $this->parameters['f']['w'] = (int) $width;
    $this->parameters['f']['h'] = (int) $height;
    return $this;
  }

  /**
   * Set focal point by width.
   *
   * @param string|int $width
   *   The width.
   *
   * @return $this
   */
  public function focalWidth($width):self {
    $this->parameters['fw']['w'] = (int) $width;
    return $this;
  }

  /**
   * Set size.
   *
   * @param string|int $width
   *   The width.
   * @param string|int $height
   *   The height.
   * @param string $anchor
   *   The anchor.
   *   Options:
   *     left-top
   *     center-top
   *     right-top
   *     left-center,
   *     center-center
   *     right-center
   *     left-bottom
   *     center-bottom
   *     right-bottom.
   *
   * @return $this
   */
  public function scaleCrop($width, $height, $anchor = 'center-center'):self {
    $anchorKeys = array_flip($this->valueKeys['a']);
    if (!isset($anchorKeys[$anchor])) {
      throw new \InvalidArgumentException('Invalid anchor value.');
    }
    $this->parameters['sc']['w'] = (int) $width;
    $this->parameters['sc']['h'] = (int) $height;
    $this->parameters['sc']['a'] = $anchorKeys[$anchor];
    return $this;
  }

  /**
   * Get image style id.
   *
   * @return string
   *   The image style id.
   */
  public function getImageStyleName():string {
    $id = $this->convertParamsToId($this->parameters);
    return $id;
  }

  /**
   * Get image style effects.
   *
   * @return array
   *   The image style effects.
   */
  public function getImageStyleEffects():array {
    $effects = [];
    foreach ($this->parameters as $param => $config) {
      if (isset($this->effectKeys[$param])) {
        $effect = $this->effectKeys[$param];
        $effects[$effect] = [];
        foreach ($config as $key => $value) {
          $property = $this->propertyKeys[$key];
          $effects[$effect][$property] = $this->valueKeys[$key][$value] ?? $value;
        }
      }
    }
    return $effects;
  }

  /**
   * Get image style.
   *
   * @return \Drupal\image\ImageStyleInterface
   *   The image style.
   */
  public function getImageStyle():ImageStyleInterface {
    $image_style = ImageStyle::create([
      'name' => $this->getImageStyleName(),
    ]);
    foreach ($this->getImageStyleEffects() as $id => $data) {
      $image_style->addImageEffect([
        'id' => $id,
        'data' => $data,
      ]);
    }
    return $image_style;
  }

  /**
   * Set parameters.
   *
   * @param array $data
   *   The parameters.
   *
   * @return $this
   */
  public function setParameters(array $data):self {
    $this->parameters = $data;
    return $this;
  }

  /**
   * Get parameters.
   *
   * @return array
   *   The parameters.
   */
  public function getParameters():array {
    return $this->parameters;
  }

  /**
   * Get effect count.
   *
   * @return int
   *   The effect count.
   */
  public function getEffectCount():int {
    return count($this->getParameters());
  }

  /**
   * Has effect types.
   *
   * @param array $effect_types
   *   The effect types.
   *
   * @return bool
   *   If the effect types are present.
   */
  public function hasEffectTypes(array $effect_types):bool {
    $types = array_keys($this->getParameters());
    return !empty(array_intersect($types, $effect_types));
  }

  /**
   * Get width.
   *
   * @return int|null
   *   The width.
   */
  public function getWidth():int|null {
    $width = NULL;
    foreach ($this->getParameters() as $style => $config) {
      $width = $width && !empty($config['w']) ? min($width, $config['w']) : $config['w'] ?? $width;
    }
    return $width;
  }

  /**
   * Get height.
   *
   * @return int|null
   *   The height.
   */
  public function getHeight():int|null {
    $height = NULL;
    foreach ($this->getParameters() as $style => $config) {
      $height = $height && !empty($config['h']) ? min($height, $config['h']) : $config['h'] ?? $height;
    }
    return $height;
  }

  /**
   * Convert params to id.
   *
   * @param array $params
   *   The parameters.
   *
   * @return string
   *   The id.
   */
  public function convertParamsToId(array $params):string {
    $id = [];
    foreach ($params as $param => $config) {
      $key = [];
      foreach ($config as $attr => $val) {
        $key[] = $attr . '-' . $val;
      }
      $id[] = $param . '--' . implode('_', $key);
    }
    return 'neo-' . implode('~', $id);
  }

  /**
   * Convert id to params.
   *
   * @param string $id
   *   The id.
   *
   * @return array
   *   The parameters.
   */
  public function convertIdToParams(string $id):array {
    $params = [];
    $id = substr($id, 4);
    $effects = explode('~', $id);
    foreach ($effects as $effect) {
      $parts = explode('--', $effect);
      $type = $parts[0];
      if (isset($parts[1])) {
        $params[$type] = [];
        $props = explode('_', $parts[1]);
        foreach ($props as $prop) {
          $prop = explode('-', $prop);
          $params[$type][$prop[0]] = $prop[1];
        }
      }
    }
    return $params;
  }

  /**
   * Render media or file as image.
   *
   * @param Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The entity to render as an image.
   * @param string|null $alt
   *   The alt text.
   * @param string|null $title
   *   The title.
   * @param array $attributes
   *   The attributes.
   *
   * @return array
   *   The renderable array.
   */
  public function toRenderable(MediaInterface|FileInterface $entity, $alt = NULL, $title = NULL, $attributes = []):array {
    $build = [];
    if ($entity instanceof MediaInterface) {
      /** @var \Drupal\media\MediaInterface $entity */
      $entity = $entity->get('thumbnail')->entity;
      if (!$entity) {
        return $build;
      }
    }
    if ($entity instanceof FileInterface) {
      $build = [
        '#theme' => 'neo_image_style',
        '#neoImageStyle' => $this,
        '#uri' => $entity->getFileUri(),
        '#alt' => $alt,
        '#title' => $title,
        '#attributes' => $attributes,
      ];
    }
    return $build;
  }

}
