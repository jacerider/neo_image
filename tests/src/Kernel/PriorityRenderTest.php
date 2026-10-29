<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\Core\File\FileExists;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_image\NeoImage;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests **priority**: the option, its markup, its preloads and the grant.
 *
 * A render carrying priority must change three things and nothing else: its
 * `<img>` loads eagerly at high fetch priority, the page head preloads the
 * image each viewport paints, and those preloads ride the render array's
 * attachments so a render-cached copy still carries them. A render that never
 * asked for priority must answer the array it always answered.
 *
 * **The preload set is asserted against the markup, not against literals.**
 * The acceptance is that each preload fetches exactly what the picture paints
 * where its media matches, so every href is compared with the `srcset` or
 * `src` the same render emitted, and every media with the window it must
 * cover. A literal list would still pass after the two had drifted apart.
 *
 * **Why kernel.** The URLs come from built image styles, the attributes from
 * core's image preprocess, and the attachments from the theme manager's
 * bubbling. A unit test would assert none of them.
 */
#[Group('neo_image')]
final class PriorityRenderTest extends KernelTestBase {

  /**
   * The fixture image.
   */
  private const URI = 'public://priority-test.png';

  /**
   * Breakpoint options naming three of the five breakpoints.
   */
  private const RESPONSIVE = [
    'sm' => ['op' => 'scale', 'width' => 400],
    'md' => ['op' => 'scale', 'width' => 800],
    'xl' => ['op' => 'scale', 'width' => 1600],
  ];

  /**
   * Effect-keyed options.
   */
  private const SINGLE = ['scaleCrop' => ['width' => 200, 'height' => 100]];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'neo_settings',
    // `neo-image.html.twig` uses `neo_attributes`, a filter `neo_twig`
    // registers.
    'neo_twig',
    'neo_image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'image']);
    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      self::URI,
      FileExists::Replace
    );
  }

  /**
   * Without the option, nothing changes: no variable, no attribute, no link.
   *
   * `priority: false` is the same render as no option at all, except that it
   * says so on the array, because declining is a statement a grant reads.
   */
  public function testWithoutPriorityTheRenderIsUnchanged(): void {
    foreach (['responsive' => self::RESPONSIVE, 'single' => self::SINGLE] as $shape => $options) {
      $plain = TwigExtension::renderImage(self::URI, $options, 'Alt');
      $this->assertArrayNotHasKey('#priority', $plain, "A $shape render that never mentions priority carries no variable.");

      $html = $this->markup($plain);
      $this->assertStringNotContainsString('fetchpriority', $html);
      $this->assertStringNotContainsString('loading="eager"', $html);
      $this->assertStringContainsString('loading="lazy"', $html, "A $shape render keeps core's lazy loading.");
      $this->assertSame([], $plain['#attached']['html_head'] ?? [], "A $shape render attaches no preload.");

      $declined = TwigExtension::renderImage(self::URI, $options + ['priority' => FALSE], 'Alt');
      $this->assertFalse($declined['#priority']);
      $this->assertSame($html, $this->markup($declined), "A declined $shape render emits the same markup.");
      $this->assertSame([], $declined['#attached']['html_head'] ?? []);

      $viaImage = TwigExtension::renderImage(self::URI, $options, 'Alt');
      $viaStyle = TwigExtension::renderImageStyle(self::URI, $options, 'Alt');
      $this->assertSame($this->markup($viaImage), $this->markup($viaStyle), "Both Twig names still answer the same $shape render.");
    }
  }

  /**
   * A single-style render takes the attributes and preloads its one URL.
   */
  public function testSingleStylePriority(): void {
    $build = TwigExtension::renderImageStyle(self::URI, self::SINGLE + ['priority' => TRUE], 'Alt', '', ['loading' => 'lazy']);
    $this->assertTrue($build['#priority']);

    $img = $this->images($this->markup($build))[0];
    $this->assertSame('eager', $img->getAttribute('loading'), 'Priority replaces a caller\'s lazy loading.');
    $this->assertSame('high', $img->getAttribute('fetchpriority'));
    $this->assertSame('async', $img->getAttribute('decoding'));

    $links = $this->preloads($build);
    $this->assertCount(1, $links);
    $this->assertSame($img->getAttribute('src'), $links[0]['href'], 'The preload fetches the URL the image paints.');
    $this->assertArrayNotHasKey('media', $links[0], 'A single image preloads on every viewport.');
    $this->assertSame('image', $links[0]['as']);
    $this->assertSame('high', $links[0]['fetchpriority']);
  }

  /**
   * A responsive render preloads one image per window, each the one painted.
   *
   * The picture emits a source for `xl` and `md` and an `<img>` for `sm`. The
   * preloads must be three, limited to `[80rem, ∞)`, `[48rem, 80rem)` and
   * `[0, 48rem)` — the windows where each of those paints — so no viewport
   * matches two of them.
   */
  public function testResponsivePriorityPreloadsWhatEachViewportPaints(): void {
    $build = TwigExtension::renderImage(self::URI, self::RESPONSIVE + ['priority' => TRUE], 'Alt');
    $this->assertTrue($build['#priority']);
    $html = $this->markup($build);

    $img = $this->images($html)[0];
    $this->assertSame('eager', $img->getAttribute('loading'));
    $this->assertSame('high', $img->getAttribute('fetchpriority'));
    $this->assertSame('async', $img->getAttribute('decoding'));

    $painted = [];
    foreach (Html::load($html)->getElementsByTagName('source') as $source) {
      $painted[$source->getAttribute('media')] = [$source->getAttribute('srcset'), $source->getAttribute('type')];
    }
    $this->assertSame(['(width >= 80rem)', '(width >= 48rem)'], array_keys($painted));

    $expected = [
      '(width >= 80rem)' => $painted['(width >= 80rem)'],
      '(width >= 48rem) and (width < 80rem)' => $painted['(width >= 48rem)'],
      '(width < 48rem)' => [$img->getAttribute('src'), NULL],
    ];
    $links = $this->preloads($build);
    $actual = [];
    foreach ($links as $link) {
      $actual[$link['media']] = [$link['href'], $link['type'] ?? NULL];
    }
    ksort($expected);
    ksort($actual);
    $this->assertSame($expected, $actual, 'Each preload fetches what the picture paints in its window, and nothing else.');
  }

  /**
   * The exclusive media follow the styles present, not the five breakpoints.
   */
  public function testPreloadMediaQueriesFollowTheStylesPresent(): void {
    $image = (new NeoImage(self::URI))->autoFromDimensions(['sm' => ['width' => 100]]);
    $this->assertSame(['sm' => NULL], $image->getPreloadMediaQueries(), 'One style paints everywhere.');

    $image = (new NeoImage(self::URI))->autoFromDimensions([
      'sm' => ['width' => 0],
      'md' => ['width' => 200],
      'lg' => ['width' => 300],
      '2xl' => ['width' => 400],
    ]);
    $this->assertSame([
      'md' => '(width < 64rem)',
      'lg' => '(width >= 64rem) and (width < 96rem)',
      '2xl' => '(width >= 96rem)',
    ], $image->getPreloadMediaQueries(), 'The controlling image has no lower bound even when it is not sm.');
  }

  /**
   * The preloads are attachments, so a render-cached copy still carries them.
   */
  public function testPreloadsSurviveTheRenderCache(): void {
    $renderer = $this->container->get('renderer');
    $wrap = fn (array $image) => [
      '#cache' => ['keys' => ['neo_image', 'priority_test']],
      'image' => $image,
    ];

    $first = $wrap(TwigExtension::renderImage(self::URI, self::RESPONSIVE + ['priority' => TRUE], 'Alt'));
    $markup = (string) $renderer->renderInIsolation($first);
    $this->assertCount(3, $first['#attached']['html_head'] ?? []);

    // The same cache keys around an image that asks for nothing: only a
    // cache hit can answer the priority markup and its three preloads.
    $second = $wrap(TwigExtension::renderImage(self::URI, self::RESPONSIVE, 'Alt'));
    $this->assertSame($markup, (string) $renderer->renderInIsolation($second), 'The second render was served from the render cache.');
    $this->assertEquals($first['#attached']['html_head'], $second['#attached']['html_head'] ?? NULL, 'The cached render carries the same preloads.');
  }

  /**
   * A grant goes to the first render that does not decline it, and only once.
   */
  public function testTheGrantIsClaimedOnceByTheFirstRenderThatDoesNotDecline(): void {
    /** @var \Drupal\neo_image\NeoImagePriority $priority */
    $priority = $this->container->get('neo_image.priority');

    $priority->grant();
    $declined = TwigExtension::renderImage(self::URI, self::SINGLE + ['priority' => FALSE]);
    $first = TwigExtension::renderImage(self::URI, self::RESPONSIVE);
    $second = TwigExtension::renderImage(self::URI, self::SINGLE);
    $this->assertStringNotContainsString('fetchpriority', $this->markup($declined), 'A declining render leaves the grant.');
    $this->assertStringContainsString('fetchpriority="high"', $this->markup($first), 'The next render claims it.');
    $this->assertCount(3, $first['#attached']['html_head'] ?? [], 'A claimed grant preloads like the option.');
    $this->assertStringNotContainsString('fetchpriority', $this->markup($second), 'A claimed grant is spent.');
    $this->assertFalse($priority->withdraw(), 'Nothing is outstanding after the claim.');

    $priority->grant();
    $this->assertTrue($priority->withdraw(), 'An unclaimed grant is reported when withdrawn.');
    $after = TwigExtension::renderImage(self::URI, self::SINGLE);
    $this->assertStringNotContainsString('fetchpriority', $this->markup($after), 'A withdrawn grant reaches nothing.');

    // An explicit request spends an outstanding grant, so one page cannot end
    // up with two priority images.
    $priority->grant();
    $explicit = TwigExtension::renderImage(self::URI, self::SINGLE + ['priority' => TRUE]);
    $this->markup($explicit);
    $this->assertFalse($priority->withdraw(), 'An explicit priority claims the grant too.');
  }

  /**
   * The PHP render arrays take priority as `#priority`.
   */
  public function testRenderArraysTakePriorityDirectly(): void {
    $build = (new NeoImage(self::URI))->autoFromDimensions(self::RESPONSIVE)->toRenderable('Alt');
    $build['#priority'] = TRUE;
    $html = $this->markup($build);
    $this->assertStringContainsString('fetchpriority="high"', $html);
    $this->assertCount(3, $build['#attached']['html_head'] ?? []);
  }

  /**
   * Renders a build and answers its markup.
   *
   * @param array $build
   *   The render array, which receives the bubbled attachments.
   *
   * @return string
   *   The markup.
   */
  private function markup(array &$build): string {
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * Answers the `<img>` elements in a fragment.
   *
   * @param string $html
   *   The markup.
   *
   * @return \DOMElement[]
   *   The image elements.
   */
  private function images(string $html): array {
    return iterator_to_array(Html::load($html)->getElementsByTagName('img'));
  }

  /**
   * Answers the attributes of every preload a rendered build attached.
   *
   * @param array $build
   *   The rendered build.
   *
   * @return array[]
   *   One attribute array per preload link.
   */
  private function preloads(array $build): array {
    $links = [];
    foreach ($build['#attached']['html_head'] ?? [] as [$element]) {
      if (($element['#attributes']['rel'] ?? NULL) === 'preload') {
        $links[] = $element['#attributes'];
      }
    }
    return $links;
  }

}
