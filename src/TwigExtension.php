<?php

namespace Drupal\neo_image;

use Drupal\Core\Template\Attribute;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Defines Twig extensions.
 *
 * Every parameter and every return below carries a **documented type** and no
 * declared one. phpstan level 6 is satisfied by a docblock exactly as it is by
 * a signature, and a declared type is the only edit available here that could
 * turn a value a template has always passed into a `TypeError` on the sites
 * this package ships to. Each type is the **accepted shape** — the union of
 * what call sites actually pass — and the prose beneath every one says what
 * happens to everything else, because nothing is rejected.
 *
 * Array value types are spelled out literally rather than through a
 * `@phpstan-type` alias, because many sites analyse this package with their
 * own configurations and a tag some of those runs cannot resolve is worse
 * than the repetition.
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
   * @param mixed $mixed
   *   The subject the Twig function was handed. Only one shape is read here:
   *   a string naming an external URL whose path ends `{width}x{height}.png`.
   *   Every other subject in the union the public functions document — a
   *   media entity, a file entity, NULL — and every value outside it comes
   *   back untouched, which is why this parameter is `mixed` rather than that
   *   union: this step forwards what it does not read.
   * @param mixed $options
   *   The options the Twig function was handed. A non-empty array is scanned
   *   for the largest `width` and the largest `height` it names anywhere,
   *   whether it is keyed by effect or by breakpoint. Anything else — an
   *   empty array, a non-array, options naming no dimensions — leaves the
   *   subject unchanged.
   *
   * @return mixed
   *   The subject, rewritten only when every condition above held, and
   *   otherwise exactly the value that arrived.
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
   * @param string|\Drupal\media\MediaInterface|\Drupal\file\FileInterface|null $mixed
   *   The image to render: a URI or web path string, a media entity, a file
   *   entity, or nothing. Nothing is rejected — the type is documented, not
   *   declared — so a subject outside this union, and NULL itself, reaches
   *   whichever shape is chosen, matches neither of its guards and answers an
   *   empty render array.
   * @param mixed $options
   *   The image options. An array is read two ways: keyed by effect —
   *   `width`, `height`, `crop` and the rest the style setters name — it is a
   *   single-style render, and keyed `sm`, `md`, `lg`, `xl` or `2xl`, each
   *   holding its own effect-keyed array, it is a responsive render. The type
   *   is `mixed` because a non-array really is accepted and really is ignored:
   *   it is replaced with an empty array below. Documenting it as an array
   *   would make that guard a phpstan finding, and deleting the guard is a
   *   narrowing this file does not take.
   * @param string|\Stringable|null $alt
   *   The alt text: a string, a stringable — a `|t` in a template is the live
   *   case — or NULL, which `neo_toolbar` passes explicitly at two call
   *   sites. It is handed on untouched; what an empty value falls back to is
   *   the image's business, not this layer's.
   * @param string|\Stringable|null $title
   *   The title text, on the same terms as the alt text.
   * @param array<string, mixed>|\Drupal\Core\Template\Attribute $attributes
   *   Attributes for the rendered image, keyed by attribute name. An
   *   `Attribute` object is accepted as well — `neo_toolbar` passes one — and
   *   is flattened to its array form here, before the shape is chosen, so
   *   neither shape ever sees the object.
   *
   * @return array<string, mixed>
   *   The render array: a responsive render when the options name
   *   breakpoints, a single-style render otherwise, and an empty array for a
   *   subject neither shape reads.
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
   *
   * @param string|\Drupal\media\MediaInterface|\Drupal\file\FileInterface|null $mixed
   *   The image to render, as the dispatch documents it. A subject outside
   *   that union, and NULL itself, matches neither guard here and answers an
   *   empty render array.
   * @param array<string, mixed> $options
   *   Breakpoint options: `sm`, `md`, `lg`, `xl` or `2xl`, each holding its
   *   own effect-keyed array. The dispatch has already established that at
   *   least one of those keys is present, and the placeholder swap has
   *   already read every dimension in them.
   * @param string|\Stringable|null $alt
   *   The alt text, as the dispatch documents it.
   * @param string|\Stringable|null $title
   *   The title text, as the dispatch documents it.
   * @param array<string, mixed> $attributes
   *   Attributes for the rendered image, keyed by attribute name. The
   *   dispatch has already flattened an `Attribute` object into this form.
   *
   * @return array<string, mixed>
   *   The render array, or an empty array for a subject this shape does not
   *   read.
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
   *
   * @param string|\Drupal\media\MediaInterface|\Drupal\file\FileInterface|null $mixed
   *   The image to render, as the dispatch documents it. A subject outside
   *   that union, and NULL itself, matches neither guard here and answers an
   *   empty render array.
   * @param array<string, mixed> $options
   *   Effect-keyed options — `width`, `height`, `crop` and the rest the style
   *   setters name — handed straight to the style. What is *in* the array is
   *   the style's business: a bad anchor or a scale with neither width nor
   *   height raises from the setter, and nothing here catches it. An empty
   *   array is the default style.
   * @param string|\Stringable|null $alt
   *   The alt text, as the dispatch documents it.
   * @param string|\Stringable|null $title
   *   The title text, as the dispatch documents it.
   * @param array<string, mixed> $attributes
   *   Attributes for the rendered image, keyed by attribute name. The
   *   dispatch has already flattened an `Attribute` object into this form.
   *
   * @return array<string, mixed>
   *   The render array, or an empty array for a subject this shape does not
   *   read.
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
   * @param string|\Drupal\media\MediaInterface|\Drupal\file\FileInterface|null $mixed
   *   The image to render: a URI or web path string, a media entity, a file
   *   entity, or nothing. The README documents the entity form,
   *   `neo_alchemist`'s image shape supplies the string form and its
   *   remote-video shape supplies the nothing. Nothing is rejected: a subject
   *   outside this union, and NULL itself, answers an empty render array.
   * @param mixed $options
   *   The image options, as the dispatch documents them: effect-keyed
   *   dimensions or breakpoint options, with a non-array accepted and
   *   ignored.
   * @param string|\Stringable|null $alt
   *   The alt text: a string, a stringable — a `|t` in a template is the live
   *   case — or NULL, which `neo_toolbar` passes explicitly at two call
   *   sites.
   * @param string|\Stringable|null $title
   *   The title text, on the same terms as the alt text.
   * @param array<string, mixed>|\Drupal\Core\Template\Attribute $attributes
   *   Attributes for the rendered image, keyed by attribute name, or an
   *   `Attribute` object, which `neo_toolbar` passes.
   *
   * @return array<string, mixed>
   *   The render array, or an empty array for a subject this function does
   *   not read.
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
   * @param string|\Drupal\media\MediaInterface|\Drupal\file\FileInterface|null $mixed
   *   The image to render: a URI or web path string, a media entity, a file
   *   entity, or nothing. The README documents the entity form,
   *   `neo_alchemist`'s image shape supplies the string form and its
   *   remote-video shape supplies the nothing. Nothing is rejected: a subject
   *   outside this union, and NULL itself, answers an empty render array.
   * @param mixed $options
   *   The image options, as the dispatch documents them: effect-keyed
   *   dimensions or breakpoint options, with a non-array accepted and
   *   ignored.
   * @param string|\Stringable|null $alt
   *   The alt text: a string, a stringable — a `|t` in a template is the live
   *   case — or NULL, which `neo_toolbar` passes explicitly at two call
   *   sites.
   * @param string|\Stringable|null $title
   *   The title text, on the same terms as the alt text.
   * @param array<string, mixed>|\Drupal\Core\Template\Attribute $attributes
   *   Attributes for the rendered image, keyed by attribute name, or an
   *   `Attribute` object, which `neo_toolbar` passes.
   *
   * @return array<string, mixed>
   *   The render array, or an empty array for a subject this function does
   *   not read.
   *
   * @see docs/adr/0013-the-two-twig-render-functions-answer-the-same-thing.md
   */
  public static function renderImageStyle($mixed, $options = [], $alt = '', $title = '', $attributes = []) {
    return static::renderDispatch($mixed, $options, $alt, $title, $attributes);
  }

  /**
   * Render the neo image style.
   *
   * A URL in every case, including the cases that are not URLs. A string
   * subject answers what the URI **entry point** answers and an entity subject
   * what the entity **entry point** answers; a subject this function does not
   * read answers `''`.
   *
   * That last answer used to be an empty array, which every caller then used
   * as a URL — three of this site's heroes feed it straight into an inline
   * `background-image`, so a slide saved without an image rendered
   * `background-image: url('Array')`. NULL is how that subject arrives: an
   * unset prop, or a template variable that was never defined.
   *
   * `''` is not `'#'`, and the difference is deliberate. `'#'` comes back from
   * the entity entry point for a subject that *was* read and resolved to no
   * file — the **failure contract** of a URL entry point, which throws
   * nothing. Two states, two answers.
   *
   * `$alt` and `$title` are accepted and ignored, because a URL carries no
   * alt. They stay because their position is the contract of every call site,
   * the module README and `neo_alchemist`'s generated per-prop Twig hints.
   *
   * @param string|\Drupal\media\MediaInterface|\Drupal\file\FileInterface|null $mixed
   *   The image to resolve: a URI or web path string, a media entity, a file
   *   entity, or nothing. Nothing is rejected: a subject outside this union,
   *   and NULL itself, answers `''`.
   * @param array<string, mixed> $options
   *   Effect-keyed options — `width`, `height`, `crop` and the rest the style
   *   setters name — handed straight to the style. This is the one Twig
   *   function that declares this parameter and so rejects a non-array where
   *   the two render functions ignore one; the inconsistency is recorded
   *   rather than resolved, because removing the declaration is the only edit
   *   in this file that could change a caller's answer.
   * @param string|\Stringable|null $alt
   *   Accepted and ignored. A URL carries no alt.
   * @param string|\Stringable|null $title
   *   Accepted and ignored. A URL carries no title.
   *
   * @return string
   *   The URL, always a string: what the URI entry point answers for a string
   *   subject, what the entity entry point answers for an entity one — `'#'`
   *   included, which is that entry point's failure contract — and `''` for a
   *   subject this function does not read.
   *
   * @see \Drupal\Tests\neo_image\Kernel\UrlFunctionAnswersAStringTest
   */
  public static function renderImageStyleUrl($mixed, array $options = [], $alt = '', $title = '') {
    $mixed = static::placeholderSwap($mixed, $options);
    if (is_string($mixed)) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toUrlFromUri($mixed);
    }
    elseif ($mixed instanceof MediaInterface || $mixed instanceof FileInterface) {
      $neoImageStyle = new NeoImageStyle($options);
      return $neoImageStyle->toUrlFromEntity($mixed);
    }
    return '';
  }

}
