<?php

namespace Drupal\neo_image;

use Drupal\Core\Template\Attribute;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Defines Twig extensions.
 */
class TwigExtension extends AbstractExtension {

  /**
   * The option keys that mark a set of options as breakpoint options.
   */
  protected const BREAKPOINT_KEYS = ['sm', 'md', 'lg', 'xl', '2xl'];

  /**
   * Gets a unique identifier for this Twig extension.
   *
   * @return string
   *   A unique identifier for this Twig extension.
   */
  public function getName() {
    return 'twig.neo_image';
  }

  /**
   * {@inheritdoc}
   *
   * The three callables are static, against `static::class` rather than a
   * spelled-out class name so a decorated or subclassed extension still wins.
   * Twig compiles an array callable whose first element is a class string, on
   * a static method, into a direct static call; an instance callable compiles
   * into an extension lookup at every call site. The tagged service does not
   * go away and cannot: Twig needs one to call this method on.
   */
  public function getFunctions() {
    return [
      new TwigFunction('neo_image', [static::class, 'renderImage']),
      new TwigFunction('neo_image_style', [static::class, 'renderImageStyle']),
      new TwigFunction('neo_image_style_url', [static::class, 'renderImageStyleUrl']),
    ];
  }

  /**
   * Swap placeholder image sizes in the URL.
   *
   * Idempotent: it rewrites a URL's trailing `{width}x{height}` to the largest
   * dimensions the options name, and a second pass matches what the first pass
   * wrote and computes the same maxima from the same options. That is why the
   * dispatch may run it once where the delegation it replaces ran it two or
   * three times, and it is asserted rather than assumed.
   *
   * @see \Drupal\Tests\neo_image\Unit\PlaceholderSwapIdempotenceTest
   */
  protected static function placeholderSwap($mixed, $options = []) {
    if (is_string($mixed) && is_array($options) && !empty($options) && NeoImageStyle::isExternalUri($mixed)) {
      if (preg_match('/\/(\d+)x(\d+)\.png$/', $mixed, $matches)) {
        $width = $matches[1];
        $height = $matches[2];
        // For placeholder images such as https://placehold.co/300x200.png,
        // adjust the size in the URL to match the selected size.
        if ($width && $height) {
          if (strpos($mixed, $width . 'x' . $height) !== FALSE) {
            $sizeWidth = 0;
            $sizeHeight = 0;
            foreach ($options as $sizeValues) {
              if (isset($sizeValues['width'])) {
                $sizeWidth = $sizeValues['width'] > $sizeWidth ? $sizeValues['width'] : $sizeWidth;
              }
              if (isset($sizeValues['height'])) {
                $sizeHeight = $sizeValues['height'] > $sizeHeight ? $sizeValues['height'] : $sizeHeight;
              }
            }
            $finalSize = ($sizeWidth ?: $width) . 'x' . ($sizeHeight ?: $height);
            $mixed = str_replace($width . 'x' . $height, $finalSize, $mixed);
          }
        }
      }
    }
    return $mixed;
  }

  /**
   * The one render dispatch both Twig render functions hand their arguments to.
   *
   * It normalises the arguments once, reads the options for breakpoint options,
   * and builds either the responsive render or the single-style render.
   * Neither public function chooses, and neither calls the other.
   *
   * The placeholder swap runs here, once, before the shape is chosen — the
   * arrangement this replaces ran it once per hop of a two-way delegation.
   *
   * @see \Drupal\Tests\neo_image\Kernel\RenderDispatchEquivalenceTest
   */
  protected static function renderDispatch($mixed, $options = [], $alt = '', $title = '', $attributes = []) {
    $mixed = static::placeholderSwap($mixed, $options);
    if (!is_array($options)) {
      // If options is not an array, ignore it.
      $options = [];
    }
    if ($attributes instanceof Attribute) {
      $attributes = $attributes->toArray();
    }
    if (array_intersect_key($options, array_flip(static::BREAKPOINT_KEYS))) {
      return static::responsiveRender($mixed, $options, $alt, $title, $attributes);
    }
    return static::singleStyleRender($mixed, $options, $alt, $title, $attributes);
  }

  /**
   * Builds the responsive render: one source per breakpoint the options name.
   *
   * An external subject has no derivatives to build, so it falls through to the
   * single-style render with *no* options at all: the breakpoint sizes are
   * dropped rather than carried across. The placeholder swap has already read
   * them by the time that happens.
   */
  protected static function responsiveRender($mixed, array $options, $alt, $title, $attributes) {
    if (is_string($mixed)) {
      $uri = NeoImageStyle::rewritePublicPath($mixed);
      if (NeoImageStyle::isExternalUri($uri)) {
        return static::singleStyleRender($mixed, [], $alt, $title, $attributes);
      }
      $neoImage = new NeoImage($uri);
      $neoImage->autoFromDimensions($options);
      return $neoImage->toRenderable($alt, $title, $attributes);
    }
    if ($mixed instanceof MediaInterface || $mixed instanceof FileInterface) {
      return NeoImage::createFromEntity($mixed)->toRenderable($alt, $title, $attributes);
    }
    return [];
  }

  /**
   * Builds the single-style render: one image under one style.
   */
  protected static function singleStyleRender($mixed, array $options, $alt, $title, $attributes) {
    if (is_string($mixed)) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toRenderableFromUri($mixed, $alt, $title, $attributes);
    }
    if ($mixed instanceof MediaInterface || $mixed instanceof FileInterface) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toRenderableFromEntity($mixed, $alt, $title, $attributes);
    }
    return [];
  }

  /**
   * Render the neo image style.
   *
   * An exact synonym of renderImageStyle(). Both names answer whichever shape
   * the options ask for, and neither narrows to one.
   *
   * @see docs/adr/0013-the-two-twig-render-functions-answer-the-same-thing.md
   */
  public static function renderImage($mixed, $options = [], $alt = '', $title = '', $attributes = []) {
    return static::renderDispatch($mixed, $options, $alt, $title, $attributes);
  }

  /**
   * Render the neo image style.
   *
   * An exact synonym of renderImage(). Both names answer whichever shape the
   * options ask for, and neither narrows to one.
   *
   * @see docs/adr/0013-the-two-twig-render-functions-answer-the-same-thing.md
   */
  public static function renderImageStyle($mixed, $options = [], $alt = '', $title = '', $attributes = []) {
    return static::renderDispatch($mixed, $options, $alt, $title, $attributes);
  }

  /**
   * Render the neo image style.
   */
  public static function renderImageStyleUrl($mixed, array $options = [], $alt = '', $title = '') {
    $build = [];
    $mixed = static::placeholderSwap($mixed, $options);
    if (is_string($mixed)) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toUrlFromUri($mixed);
    }
    elseif ($mixed instanceof MediaInterface || $mixed instanceof FileInterface) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toUrlFromEntity($mixed);
    }
    return $build;
  }

}
