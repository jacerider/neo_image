<?php

namespace Drupal\neo_image;

use Drupal\Component\Utility\UrlHelper;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\ImageStyleInterface;
use Drupal\media\MediaInterface;
use Drupal\neo\Helpers\Str;

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
    'cs' => 'image_crop_sides',
    'sc' => 'image_scale_and_crop',
    'f' => 'focal_point_scale_and_crop',
    'fw' => 'focal_point_crop_by_width',
    'e' => 'exact',
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
    'cs' => 'Crop Sides',
    'sc' => 'Scale and Crop',
    'f' => 'Focal Scale and Crop',
    'fw' => 'Focal Scale by Width',
    'e' => 'Exact',
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
    'bg' => 'background',
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
   * Constructs a new image style.
   *
   * @param array $options
   *   The options.
   */
  public function __construct(array $options = []) {
    if ($options) {
      $allowed = [
        'auto',
        'size',
        'scale',
        'scaleCrop',
        'crop',
        'cropSides',
        'focal',
        'focalWidth',
        'exact',
      ];
      foreach ($options as $key => $option) {
        if (method_exists($this, $key) && in_array($key, $allowed)) {
          $option = is_array($option) ? $option : [];
          $this->$key(...$option);
        }
      }
    }
  }

  /**
   * Get image style URL for a media entity.
   *
   * The **failure contract** of this **entry point** is a throw, which is the
   * fourth behaviour of the four this module used to carry and the one that
   * does not match its return shape: every other URL entry point answers
   * `'#'`. It keeps the throw — changing it would be a behaviour change on a
   * method with no caller here — and it is a strict subset of
   * `toUrlFromEntity()`, which is the one to reach for.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media object containing the thumbnail file.
   *
   * @return string
   *   The URL of the media entity.
   *
   * @throws \InvalidArgumentException
   *   When the media resolves to no file.
   *
   * @deprecated in neo_image:1.1.0 and is removed from neo_image:2.0.0. Use
   *   \Drupal\neo_image\NeoImageStyle::toUrlFromEntity() instead.
   *
   * @see \Drupal\neo_image\NeoImageStyle::toUrlFromEntity()
   */
  public function buildUrlForMedia(MediaInterface $media):string {
    $file = NeoImageUtility::resolvedFile($media);
    if (!$file) {
      throw new \InvalidArgumentException('The media entity does not have a thumbnail file.');
    }
    return $this->getImageStyle()->buildUrl($file->getFileUri());
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
   * @param bool $exact
   *   If the size should be exact.
   * @param string|null $bg
   *   The background color for exact size, e.g. #ffffff or ffffff.
   *
   * @return $this
   */
  public function auto($width = NULL, $height = NULL, $exact = FALSE, $bg = NULL):self {
    if (!$width && !$height) {
      throw new \InvalidArgumentException('Width or height must be set.');
    }
    if ($width && $height) {
      if ($exact) {
        $this->exact($width, $height, NULL, $bg);
      }
      else {
        $this->focal($width, $height);
      }
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
   * Preprocess scale.
   *
   * @param array $data
   *   The data.
   *
   * @return array
   *   The data.
   */
  protected function preprocessImageScale(array $data):array {
    $data['upscale'] = TRUE;
    return $data;
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
   * Use cropauto on image.
   *
   * This will remove transparent edges from the image.
   *
   * @return $this
   */
  public function cropSides():self {
    $this->parameters['cs'] = 1;
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
   * Set exact by width and height.
   *
   * @param string|int $width
   *   The width.
   * @param string|int $height
   *   The height.
   * @param string $anchor
   *   Defaults to center-center.
   * @param string|null $bg
   *   (optional) Background color used to pad the image.
   *
   * @return $this
   */
  public function exact($width, $height, $anchor = NULL, $bg = NULL):self {
    $this->parameters['e']['w'] = (int) $width;
    $this->parameters['e']['h'] = (int) $height;
    if ($anchor) {
      $anchorKeys = array_flip($this->valueKeys['a']);
      if (!isset($anchorKeys[$anchor])) {
        throw new \InvalidArgumentException('Invalid anchor value.');
      }
      $this->parameters['e']['a'] = $anchorKeys[$anchor];
    }
    if ($bg) {
      // Background color for the canvas, e.g. #ffffff or ffffff. Remove # if
      // present.
      $this->parameters['e']['bg'] = ltrim($bg, '#');
    }
    return $this;
  }

  /**
   * Check if exact is set.
   *
   * @return bool
   *   If exact is set.
   */
  public function isExact():bool {
    return isset($this->parameters['e']);
  }

  /**
   * Get background color for exact size.
   *
   * @return string|null
   *   The background color, e.g. #ffffff, or null if not set.
   */
  public function getBg():?string {
    return $this->parameters['e']['bg'] ?? NULL;
  }

  /**
   * Preprocess exact.
   *
   * @param array $data
   *   The data.
   *
   * @return array
   *   The data.
   */
  protected function preprocessExact(array $data):array {
    $data = [
      'canvas_size' => 'exact',
      'canvas_color' => !empty($data['background']) ? '#' . ltrim($data['background'], '#') : NULL,
      'exact' => [
        'width' => $data['width'] . 'px',
        'height' => $data['height'] . 'px',
        'placement' => $data['anchor'] ?? 'center-center',
        'x_offset' => 0,
        'y_offset' => 0,
      ],
    ];
    return $data;
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
        if (is_array($config)) {
          foreach ($config as $key => $value) {
            $property = $this->propertyKeys[$key];
            $effects[$effect][$property] = $this->valueKeys[$key][$value] ?? $value;
          }
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
      $callback = Str::camel('preprocess_' . $id);
      if (method_exists($this, $callback)) {
        $data = $this->$callback($data);
      }
      $image_style->addImageEffect([
        'id' => $id,
        'data' => $data,
      ]);
    }
    $effectId = version_compare(\Drupal::VERSION, '11.2.0', '>=') ? 'image_convert_avif' : 'image_convert';
    $image_style->addImageEffect([
      'id' => $effectId,
      'data' => [
        'extension' => 'webp',
      ],
    ]);
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
      if (is_array($config)) {
        foreach ($config as $attr => $val) {
          $key[] = $attr . '-' . $val;
        }
        $id[] = $param . '--' . implode('_', $key);
      }
      else {
        $id[] = $param;
      }
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
      else {
        $params[$type] = 1;
      }
    }
    return $params;
  }

  /**
   * Check if URI is external.
   *
   * @param string $uri
   *   The URI.
   *
   * @return bool
   *   If the URI is external.
   */
  public static function isExternalUri(string $uri):bool {
    if (UrlHelper::isExternal($uri)) {
      return TRUE;
    }
    return strpos($uri, 'public://') !== 0;
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
   *   The renderable array. Empty when the entity resolves to no file — the
   *   **failure contract** of a render **entry point**, which renders nothing
   *   and throws nothing. A caller that wants to know it happened asks
   *   `NeoImageUtility::resolvedFile()` first.
   */
  public function toRenderableFromEntity(MediaInterface|FileInterface $entity, $alt = NULL, $title = NULL, $attributes = []):array {
    $build = [];
    // The authored alt and title come from the one derivation both renders
    // share, so this render answers what the responsive render answers for the
    // same subject. It is read from the subject itself rather than from the
    // file it resolves to. An empty supplied value counts as unsupplied: every
    // Twig entry point defaults its alt argument to the empty string, so a
    // fallback that only caught NULL would be inert on exactly those paths.
    // Where the entity authored nothing there is nothing to fall back to, so
    // the supplied value survives as itself.
    $authored = NeoImageUtility::authoredAltAndTitle($entity);
    if ($alt === NULL || $alt === '') {
      $alt = $authored['alt'] ?? $alt;
    }
    if ($title === NULL || $title === '') {
      $title = $authored['title'] ?? $title;
    }
    $file = NeoImageUtility::resolvedFile($entity);
    if (!$file) {
      return $build;
    }
    return [
      '#theme' => 'neo_image_style',
      '#neoImageStyle' => $this,
      '#uri' => $file->getFileUri(),
      '#alt' => $alt,
      '#title' => $title,
      '#attributes' => $attributes,
    ];
  }

  /**
   * Render uri as image.
   *
   * @param string $uri
   *   The file URI.
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
  public function toRenderableFromUri(string $uri, $alt = NULL, $title = NULL, $attributes = []):array {
    return [
      '#theme' => 'neo_image_style',
      '#neoImageStyle' => $this,
      '#uri' => $uri,
      '#alt' => $alt,
      '#title' => $title,
      '#attributes' => $attributes,
    ];
  }

  /**
   * Converts a given URI to a URL using the image style.
   *
   * @param string $uri
   *   The URL of the image to be converted.
   * @param bool $ensure
   *   Whether to ensure the image style derivative exists.
   *
   * @return string
   *   The URL of the image after applying the image style. A URI this module
   *   calls external — which is every URI that is not on the public stream,
   *   `private://` included — is answered unchanged. That is why this is not
   *   the method `toUrlFromEntity()` delegates to; see `docs/adr/0011`.
   */
  public function toUrlFromUri(string $uri, bool $ensure = FALSE):string {
    $uri = str_replace('/sites/default/files/', 'public://', $uri);
    if (self::isExternalUri($uri)) {
      return $uri;
    }
    $style = $this->getImageStyle();
    if ($ensure) {
      $this->ensureDerivative($style, $uri);
    }
    return $style->buildUrl($uri);
  }

  /**
   * Writes a style's derivative for a URI unless it is already on disk.
   *
   * The **derivative ensure**, and the one thing the two URL **entry points**
   * share. Both carried a verbatim copy of it; this is that copy, written
   * once, so the two cannot drift apart the way the four **resolved file**
   * lookups did.
   *
   * It is deliberately the *only* thing they share. They do not collapse into
   * one method: the URI entry point answers a `private://` URI unchanged and
   * the entity entry point builds the image-style URL core's private
   * derivative route depends on, so a delegation would break private files in
   * the direction that looks like the general case. `docs/adr/0011` records
   * it and a kernel test pins it.
   *
   * The public-path rewrite that sits beside this step in both callers is a
   * separate open candidate and stays where it is.
   *
   * @param \Drupal\image\ImageStyleInterface $style
   *   The image style to build the derivative with.
   * @param string $uri
   *   The URI of the source image.
   */
  private function ensureDerivative(ImageStyleInterface $style, string $uri):void {
    $styleUri = $style->buildUri($uri);
    if (!file_exists($styleUri)) {
      $style->createDerivative($uri, $styleUri);
    }
  }

  /**
   * Generates a URL for the given media or file entity.
   *
   * This method takes a MediaInterface or FileInterface entity and generates
   * a URL for the associated image style. If the entity is a MediaInterface,
   * it retrieves the thumbnail file entity. If the entity is a FileInterface,
   * it directly generates the URL for the file's URI using the image style.
   *
   * This does **not** delegate to `toUrlFromUri()`, and that is load-bearing
   * rather than history: it builds the image-style URL unconditionally, where
   * that method answers every non-public stream unchanged. A private file
   * resolves through the image style here and would come back as a raw
   * `private://` string there. `docs/adr/0011` records the decision and a
   * kernel test pins the difference. The two share the **derivative ensure**
   * and nothing else.
   *
   * @param Drupal\media\MediaInterface|\Drupal\file\FileInterface $entity
   *   The media or file entity for which to generate the URL.
   * @param bool $ensure
   *   Whether to ensure the image style derivative exists.
   *
   * @return string
   *   The generated URL for the image style, or `'#'` when the entity resolves
   *   to no file — the **failure contract** of a URL **entry point**, which
   *   throws nothing. A caller that wants to know it happened asks
   *   `NeoImageUtility::resolvedFile()` first.
   */
  public function toUrlFromEntity(MediaInterface|FileInterface $entity, $ensure = FALSE):string {
    $file = NeoImageUtility::resolvedFile($entity);
    if (!$file) {
      return '#';
    }
    $uri = $file->getFileUri();
    $uri = str_replace('/sites/default/files/', 'public://', $uri);
    $style = $this->getImageStyle();
    if ($ensure) {
      $this->ensureDerivative($style, $uri);
    }
    return $style->buildUrl($uri);
  }

}
