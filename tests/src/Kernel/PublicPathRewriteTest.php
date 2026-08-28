<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the one public-path rewrite and the three entry points that adopt it.
 *
 * The **public-path rewrite** turns a public-files web path into a stream URI
 * and leaves everything else alone. It was written five times, four of them
 * against a hardcoded `sites/default/files`; this pins the one that reads the
 * site's configured public path, and the three live call sites that reach it.
 *
 * **Why kernel and the environment is the point.** A kernel test sets
 * `file_public_path` to its own per-test files directory, so it runs under a
 * **non-default** public path on every run. The configuration this bug needs is
 * the configuration the suite already has: the assertions below fail against a
 * hardcoded rewrite without a fixture arranging anything, and the base-path
 * accessor they assert wants a container, which is what keeps them out of the
 * unit directory.
 *
 * **No default-path assertion is possible here.** "Nothing changes on a site
 * running the default public path" is discharged by reading the diff, because a
 * kernel test never runs on the default path.
 */
#[Group('neo_image')]
final class PublicPathRewriteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'neo_settings',
    // `neo-image.html.twig` uses `neo_attributes`, a filter `neo_twig`
    // registers. Twig parses the whole template whichever branch renders.
    'neo_twig',
    'neo_image',
  ];

  /**
   * The site's configured public files path, per this test run.
   */
  private string $publicPath;

  /**
   * The fixture image, as the root-relative web path a template hands over.
   */
  private string $webPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['field', 'system', 'image', 'file']);

    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      'public://image-test.png',
      FileExists::Replace
    );
    $file = File::create(['uri' => 'public://image-test.png']);
    $file->setPermanent();
    $file->save();

    $this->publicPath = PublicStream::basePath();
    $this->webPath = '/' . $this->publicPath . '/image-test.png';
  }

  /**
   * It rewrites a public-files web path under the site's configured path.
   *
   * Acceptance criterion: it rewrites a public-files web path to a stream uri
   * under the site's configured public path.
   *
   * The first assertion states the fixture rather than the behaviour: if this
   * suite ever ran on the default public path, every assertion below would pass
   * against a hardcoded rewrite and this class would be asserting nothing.
   */
  public function testWebPathBecomesStreamUriUnderTheConfiguredPublicPath(): void {
    $this->assertNotSame(
      'sites/default/files',
      $this->publicPath,
      'The suite runs under a non-default public path, which is what makes these assertions mean anything.'
    );

    $this->assertSame(
      'public://image-test.png',
      NeoImageStyle::rewritePublicPath($this->webPath),
      'A public-files web path becomes a stream uri.'
    );
    $this->assertSame(
      'public://nested/dir/image-test.png',
      NeoImageStyle::rewritePublicPath('/' . $this->publicPath . '/nested/dir/image-test.png'),
      'So does one below a subdirectory of the public files path.'
    );
  }

  /**
   * It hands back everything that is not a public-files web path.
   *
   * Acceptance criterion: it returns a stream uri, an absolute external url,
   * and a path outside the public files path unchanged.
   *
   * `/sites/default/files/…` is in the list deliberately: under this suite's
   * public path it *is* a path outside the public files path, so the answer
   * that the four hardcoded rewrites gave it is now the wrong one.
   */
  public function testItReturnsEverythingElseUnchanged(): void {
    $cases = [
      'a public stream uri' => 'public://image-test.png',
      'a private stream uri' => 'private://image-test.png',
      'an absolute external url' => 'https://example.com/image-test.png',
      'a protocol-relative external url' => '//example.com/image-test.png',
      'core\'s default public path, which is not this site\'s' => '/sites/default/files/image-test.png',
      'a path outside the public files path' => '/themes/front/logo.png',
      'the empty string' => '',
    ];
    foreach ($cases as $description => $subject) {
      $this->assertSame(
        $subject,
        NeoImageStyle::rewritePublicPath($subject),
        ucfirst($description) . ' is returned unchanged.'
      );
    }
  }

  /**
   * The responsive render answers a picture for a public-files web path.
   *
   * Acceptance criterion: it answers a picture with per-breakpoint sources from
   * the responsive render given a public-files web path.
   *
   * The costliest of the three adoptions. Unrewritten, the path meets
   * `isExternalUri()` — which calls everything not on the public stream
   * external — and the **responsive render** falls back to an effect-less
   * **single-style render**: no `<picture>`, no per-breakpoint sources, the
   * original file at its full intrinsic size.
   *
   * Two altitudes, because either alone is weak: the render array names the
   * branch that was taken, and the markup is the promise the plan makes.
   */
  public function testResponsiveRenderAnswersPictureForWebPath(): void {
    $build = TwigExtension::renderImage($this->webPath, [
      'sm' => ['width' => 100],
      'lg' => ['width' => 300],
    ]);

    $this->assertSame(
      'neo_image',
      $build['#theme'] ?? NULL,
      'The responsive render was built, not the effect-less single-style fallback.'
    );

    $markup = $this->renderMarkup($build);
    $this->assertStringContainsString('<picture', $markup, 'It emits a picture element.');
    $this->assertStringContainsString('<source', $markup, 'With a per-breakpoint source.');
    $this->assertStringContainsString('/styles/', $markup, 'Pointing at image style derivatives.');
  }

  /**
   * The uri url entry point answers a derivative url for a web path.
   *
   * Acceptance criterion: it answers a derivative url from the uri url entry
   * point given a public-files web path.
   *
   * Unrewritten, this entry point short-circuits on `isExternalUri()` and hands
   * back the path it was given — so backgrounds and meta tags get the original
   * file rather than the size that was asked for.
   */
  public function testUriUrlEntryPointAnswersDerivativeUrlForWebPath(): void {
    $style = new NeoImageStyle(['scale' => [100]]);
    $expected = $style->getImageStyle()->buildUrl('public://image-test.png');

    $this->assertStringContainsString('/styles/' . $style->getImageStyleName() . '/public/', $expected);
    $this->assertSame(
      $expected,
      $style->toUrlFromUri($this->webPath),
      'The uri entry point answers the image style url for a public-files web path.'
    );
    $this->assertSame(
      $expected,
      TwigExtension::renderImageStyleUrl($this->webPath, ['scale' => [100]]),
      'And so does the Twig function that reaches it.'
    );
  }

  /**
   * The single-style render answers the same as before for the same web path.
   *
   * Acceptance criterion: it answers the same single-style render as before for
   * the same public-files web path.
   *
   * A regression pin rather than a fix: this is the one of the five rewrites
   * that already read the setting, so it is green before the change and has to
   * stay green through it. Its preprocess is where the measuring URI is
   * computed, which is the call site this ticket rewrites, so "unchanged" is a
   * claim worth asserting rather than assuming.
   */
  public function testSingleStyleRenderAnswersTheSameForWebPath(): void {
    $style = new NeoImageStyle(['scale' => [100]]);

    $markup = $this->renderMarkup($style->toRenderableFromUri($this->webPath, 'Fixture alt'));

    $this->assertStringNotContainsString('<picture', $markup, 'A single-style render is one img, not a picture.');
    $this->assertStringContainsString('<img', $markup);
    $this->assertStringContainsString('/styles/' . $style->getImageStyleName() . '/public/', $markup, 'It points at the derivative.');
    $this->assertStringContainsString('width="100"', $markup, 'Measured through the image toolkit, at the style\'s width.');
    $this->assertStringContainsString('alt="Fixture alt"', $markup);
  }

  /**
   * It renders without a deprecation when the theme variable carries no uri.
   *
   * Acceptance criterion: it renders without a deprecation when the theme
   * variable carries no uri.
   *
   * `uri` is declared by `hook_theme()` and defaults to NULL, so a caller that
   * omits it fed NULL straight into `str_replace()`, which PHP has deprecated.
   * The rewrite's parameter is a plain string and the call site coalesces,
   * which answers exactly what the deprecated call answered — the empty
   * string — and says so in the signature.
   *
   * Deprecations are filtered to this package: core emits its own from
   * `template_preprocess_image()`, and this criterion is about ours.
   */
  public function testRendersWithoutDeprecationWhenThemeVariableCarriesNoUri(): void {
    $build = [
      '#theme' => 'neo_image_style',
      '#neoImageStyle' => new NeoImageStyle(['scale' => [100]]),
      '#alt' => 'No uri',
    ];

    $markup = '';
    $deprecations = $this->deprecationsFromThisPackage(function () use ($build, &$markup): void {
      $markup = $this->renderMarkup($build);
    });

    $this->assertSame([], $deprecations, 'Rendering with no uri raises no deprecation from neo_image.');
    $this->assertStringContainsString('<img', $markup, 'And it still renders.');
  }

  /**
   * Renders a build and returns its markup.
   *
   * @param array $build
   *   The render array.
   *
   * @return string
   *   The rendered markup.
   */
  private function renderMarkup(array $build): string {
    return (string) $this->container->get('renderer')->renderRoot($build);
  }

  /**
   * Runs an action and collects the deprecations this package raised.
   *
   * @param callable $action
   *   The action to run.
   *
   * @return string[]
   *   One entry per deprecation raised from a file in this package.
   */
  private function deprecationsFromThisPackage(callable $action): array {
    $package = DIRECTORY_SEPARATOR . 'neo_image' . DIRECTORY_SEPARATOR;
    $found = [];
    set_error_handler(
      static function (int $severity, string $message, string $file = '', int $line = 0) use (&$found, $package): bool {
        if ($severity !== E_DEPRECATED && $severity !== E_USER_DEPRECATED) {
          // Everything else falls through to the handler already installed.
          return FALSE;
        }
        if (str_contains($file, $package)) {
          $found[] = $message . ' at ' . $file . ':' . $line;
        }
        return TRUE;
      }
    );
    try {
      $action();
    }
    finally {
      restore_error_handler();
    }
    return $found;
  }

}
