<?php

namespace Drupal\neo_image;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\StreamWrapper\PublicStream;
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
   * The **id grammar**, one declaration per effect.
   *
   * This is the vocabulary *and* the rules, deliberately in one array rather
   * than in parallel arrays beside each other. A validator that keeps a second
   * copy of the vocabulary it validates against is a vocabulary that drifts,
   * and a drifted grammar answers 404 for ids this module itself produced. A
   * ninth effect is one entry here and nothing else.
   *
   * Nothing in it is invented. Every rule is read off the setter that produces
   * the effect: `id` and `label` are what the effect builds and what an editor
   * reads, `allowed` is the properties that setter can set, `required` is the
   * ones it always sets, and `required_any` — declared by `s` alone — is
   * `scale()`'s own rule that a width or a height must be given, though not
   * both. So the grammar rejects nothing the module can build.
   *
   * @var array
   */
  protected array $effects = [
    'r' => [
      'id' => 'image_resize',
      'label' => 'Resize',
      'allowed' => ['w', 'h'],
      'required' => ['w', 'h'],
    ],
    's' => [
      'id' => 'image_scale',
      'label' => 'Scale',
      'allowed' => ['w', 'h'],
      'required' => [],
      'required_any' => ['w', 'h'],
    ],
    'c' => [
      'id' => 'image_crop',
      'label' => 'Crop',
      'allowed' => ['w', 'h', 'a'],
      'required' => ['w', 'h'],
    ],
    'cs' => [
      'id' => 'image_crop_sides',
      'label' => 'Crop Sides',
      'allowed' => [],
      'required' => [],
    ],
    'sc' => [
      'id' => 'image_scale_and_crop',
      'label' => 'Scale and Crop',
      'allowed' => ['w', 'h', 'a'],
      'required' => ['w', 'h'],
    ],
    'f' => [
      'id' => 'focal_point_scale_and_crop',
      'label' => 'Focal Scale and Crop',
      'allowed' => ['w', 'h'],
      'required' => ['w', 'h'],
    ],
    'fw' => [
      'id' => 'focal_point_crop_by_width',
      'label' => 'Focal Scale by Width',
      'allowed' => ['w'],
      'required' => ['w'],
    ],
    'e' => [
      'id' => 'exact',
      'label' => 'Exact',
      'allowed' => ['w', 'h', 'a', 'bg'],
      'required' => ['w', 'h'],
    ],
  ];

  /**
   * The property vocabulary and the rule each property's value must satisfy.
   *
   * The codec validates what the codec owns and nothing else. A width or a
   * height must be the digits an integer cast answers, because every setter
   * casts them — which is also why the parse casts them back. An anchor must be
   * one of the nine short keys, because every setter that takes one already
   * throws on anything else, and `values` is the single list all of them read.
   *
   * A background is required only to be non-empty and inside the id's own
   * alphabet. It is deliberately *not* validated as a hex colour: a colour is
   * the caller's value and reaches this class straight from a Twig template, so
   * a colour typo answering 404 would be a worse failure than the wrong
   * background it produces instead.
   *
   * @var array
   */
  protected array $properties = [
    'w' => [
      'label' => 'width',
      'type' => 'integer',
    ],
    'h' => [
      'label' => 'height',
      'type' => 'integer',
    ],
    'a' => [
      'label' => 'anchor',
      'type' => 'string',
      'values' => [
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
    ],
    'bg' => [
      'label' => 'background',
      'type' => 'string',
    ],
  ];

  /**
   * The prefix every id this module owns carries.
   */
  protected const ID_PREFIX = 'neo-';

  /**
   * The characters an id value may be made of.
   */
  protected const VALUE_ALPHABET = '/^[A-Za-z0-9]+$/';

  /**
   * The digits an integer cast answers, with no redundant leading zero.
   *
   * Leading zeros are refused rather than tolerated because `w-0300` would
   * serialise back as `w-300`, and an id that comes back out as a different
   * string is exactly the **round-trip safety** failure the grammar exists to
   * close. No setter can produce one.
   */
  protected const INTEGER_VALUE = '/^(0|[1-9][0-9]*)$/';

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
   * @phpcs:ignore Drupal.Commenting.Deprecated.DeprecatedWrongSeeUrlFormat
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
      // An effect that carries no properties stores a sentinel rather than a
      // property array, so it is labelled by its own name alone.
      foreach (is_array($config) ? $config : [] as $property => $value) {
        $effectLabel[] = $this->properties[$property]['label'] . ': ' . ($this->properties[$property]['values'][$value] ?? $value);
      }
      $label[] = $this->effects[$effect]['label'] . ($effectLabel ? ' (' . implode(' | ', $effectLabel) . ')' : '');
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
    $anchorKeys = array_flip($this->properties['a']['values']);
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
    $anchorKeys = array_flip($this->properties['a']['values']);
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
      $anchorKeys = array_flip($this->properties['a']['values']);
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
      if (isset($this->effects[$param])) {
        $effect = $this->effects[$param]['id'];
        $effects[$effect] = [];
        if (is_array($config)) {
          foreach ($config as $key => $value) {
            $property = $this->properties[$key]['label'];
            $effects[$effect][$property] = $this->properties[$key]['values'][$value] ?? $value;
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
    foreach ($this->getParameters() as $config) {
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
    foreach ($this->getParameters() as $config) {
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
   * Convert id to params, refusing an id the grammar does not admit.
   *
   * The parse half of the **codec**, and the module's only contract with the
   * outside world: it is handed a name from a URL by the param converter and a
   * **derivative directory** name from disk by the style manager. It therefore
   * validates, where the serialise half does not.
   *
   * A **rejected id** throws, which is the failure convention this class
   * already uses for a bad anchor, width or height, and it keeps the declared
   * array return so nothing downstream changes signature. There is deliberately
   * no second nullable-returning parse beside it: three callers want three
   * different reactions to a refusal, and a second entry into one grammar is
   * how a grammar drifts.
   *
   * @param string $id
   *   The id.
   *
   * @return array
   *   The parameters. A width or a height comes back as an integer, because
   *   validation has already proved it is digits — which is what makes the
   *   width and height getters' declared `int` returns honest rather than
   *   dependent on PHP coercing a string.
   *
   * @throws \InvalidArgumentException
   *   When the id is outside the **id grammar**.
   */
  public function convertIdToParams(string $id):array {
    if (!str_starts_with($id, self::ID_PREFIX)) {
      throw new \InvalidArgumentException(sprintf('The image style id "%s" does not start with "%s".', $id, self::ID_PREFIX));
    }
    $body = substr($id, strlen(self::ID_PREFIX));
    if ($body === '') {
      throw new \InvalidArgumentException(sprintf('The image style id "%s" names no effect.', $id));
    }
    $params = [];
    foreach (explode('~', $body) as $segment) {
      $parts = explode('--', $segment);
      if (count($parts) > 2) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" separates the properties of "%s" more than once.', $id, $parts[0]));
      }
      $effect = $parts[0];
      if (!isset($this->effects[$effect])) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" names an unknown effect "%s".', $id, $effect));
      }
      if (isset($params[$effect])) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" repeats the effect "%s".', $id, $effect));
      }
      $config = isset($parts[1]) ? $this->parseProperties($id, $effect, $parts[1]) : [];
      $this->assertRequiredProperties($id, $effect, $config);
      // An effect that carries no properties stores the sentinel the setter
      // sets, so serialising the result answers the segment that was parsed.
      $params[$effect] = $config ?: 1;
    }
    return $params;
  }

  /**
   * Parses one effect's property list against what that effect allows.
   *
   * @param string $id
   *   The whole id, for the message.
   * @param string $effect
   *   The effect key the properties belong to.
   * @param string $list
   *   The property list, without its separator.
   *
   * @return array
   *   The properties, keyed by property key.
   *
   * @throws \InvalidArgumentException
   *   When a property is unknown, unallowed, repeated, or has no value.
   */
  protected function parseProperties(string $id, string $effect, string $list):array {
    $config = [];
    foreach (explode('_', $list) as $pair) {
      $parts = explode('-', $pair, 2);
      if (count($parts) !== 2 || $parts[1] === '') {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" gives the property "%s" of effect "%s" no value.', $id, $parts[0], $effect));
      }
      [$property, $value] = $parts;
      if (!isset($this->properties[$property])) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" names an unknown property "%s".', $id, $property));
      }
      if (!in_array($property, $this->effects[$effect]['allowed'], TRUE)) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" gives the effect "%s" a property it does not take: "%s".', $id, $effect, $property));
      }
      if (isset($config[$property])) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" repeats the property "%s" of effect "%s".', $id, $property, $effect));
      }
      $config[$property] = $this->parsePropertyValue($id, $effect, $property, $value);
    }
    return $config;
  }

  /**
   * Validates one property's value and answers it in its own type.
   *
   * @param string $id
   *   The whole id, for the message.
   * @param string $effect
   *   The effect key, for the message.
   * @param string $property
   *   The property key.
   * @param string $value
   *   The raw value.
   *
   * @return int|string
   *   The value, cast to an integer where the property is one.
   *
   * @throws \InvalidArgumentException
   *   When the value is outside the property's vocabulary.
   */
  protected function parsePropertyValue(string $id, string $effect, string $property, string $value):int|string {
    $rules = $this->properties[$property];
    if (isset($rules['values'])) {
      if (!isset($rules['values'][$value])) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" gives the %s of effect "%s" an unknown value: "%s".', $id, $rules['label'], $effect, $value));
      }
      return $value;
    }
    if (($rules['type'] ?? 'string') === 'integer') {
      if (!preg_match(self::INTEGER_VALUE, $value)) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" gives the %s of effect "%s" a value that is not a whole number: "%s".', $id, $rules['label'], $effect, $value));
      }
      return (int) $value;
    }
    if (!preg_match(self::VALUE_ALPHABET, $value)) {
      throw new \InvalidArgumentException(sprintf('The image style id "%s" gives the %s of effect "%s" a value outside the id alphabet: "%s".', $id, $rules['label'], $effect, $value));
    }
    return $value;
  }

  /**
   * Asserts one effect carries every property its declaration requires.
   *
   * @param string $id
   *   The whole id, for the message.
   * @param string $effect
   *   The effect key.
   * @param array $config
   *   The properties parsed for it.
   *
   * @throws \InvalidArgumentException
   *   When a required property is missing.
   */
  protected function assertRequiredProperties(string $id, string $effect, array $config):void {
    $rules = $this->effects[$effect];
    foreach ($rules['required'] as $property) {
      if (!isset($config[$property])) {
        throw new \InvalidArgumentException(sprintf('The image style id "%s" gives the effect "%s" no %s, which it requires.', $id, $effect, $this->properties[$property]['label']));
      }
    }
    $any = $rules['required_any'] ?? [];
    if ($any && !array_intersect($any, array_keys($config))) {
      $labels = array_map(fn (string $property): string => $this->properties[$property]['label'], $any);
      throw new \InvalidArgumentException(sprintf('The image style id "%s" gives the effect "%s" neither a %s.', $id, $effect, implode(' nor a ', $labels)));
    }
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
   * The **public-path rewrite**: a public-files web path becomes a stream URI.
   *
   * It lives beside `isExternalUri()` because it is that predicate's input
   * normalisation: every caller hands the result straight to it on the next
   * line. Without it a root-relative public-files URL — which is what a
   * template's image `src` is, and therefore what every Twig **entry point**
   * that takes a string actually receives — reads as external, and the
   * **responsive render** and the URL entry points quietly stop producing
   * derivatives.
   *
   * The base path comes from core's own accessor rather than a hand-written
   * `sites/default/files`. Core resolves `file_public_path` when it is set and
   * derives the path from the site path when it is not, so this is right on a
   * multisite as well as on a site that moved its files directory. Re-deriving
   * that default here would be the same mistake as hardcoding it, one level up.
   *
   * It is deliberately an unanchored substring replacement, exactly as the
   * copies it replaces were. An absolute same-site URL containing the public
   * path is still mangled, and a Drupal installed in a subdirectory still
   * produces a corrupted URI. Both are pre-existing and both are now a one-line
   * change in one place, which is what the extraction was for. Making either
   * here would change what a caller receives, and break the constraint this
   * ships on: a site running the default public path sees no difference at all.
   *
   * @param string $uri
   *   The URI or path. A caller whose source may be absent coalesces to the
   *   empty string rather than passing NULL: the parameter is a plain string
   *   because the answer for "nothing" is the empty string either way.
   *
   * @return string
   *   The stream URI, or the argument unchanged when it holds no public-files
   *   web path — a stream URI of any scheme, an external URL, and a path
   *   outside the public files directory all come back as they went in.
   */
  public static function rewritePublicPath(string $uri):string {
    return str_replace('/' . PublicStream::basePath() . '/', 'public://', $uri);
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
    $uri = self::rewritePublicPath($uri);
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
