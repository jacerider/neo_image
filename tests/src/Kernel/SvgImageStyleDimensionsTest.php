<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\KernelTests\KernelTestBase;
use Drupal\image\Entity\ImageStyle;
use Drupal\image\Hook\ImageThemeHooks;
use Drupal\neo_image\NeoImageUtility;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins a core image style's SVG to the size the style would give it.
 *
 * Core emits an SVG as-is, "resized to the dimensions specified by the
 * style", but computes those from the image field's stored width and height.
 * A field stores none for an SVG, because no toolkit core ships can read one,
 * so the `<img>` carried no size at all. An SVG sized only by its `viewBox`
 * then collapsed to nothing in a shrink-to-fit container: the media library's
 * upload preview showed an empty space where a logo should have been.
 *
 * `neo_image_preprocess_image_style()` reads the SVG's own dimensions and runs
 * them through the style. What it must not touch is pinned beside it: a raster
 * keeps core's answer, and an SVG whose size cannot be read stays unsized
 * rather than taking a made-up one.
 *
 * **Why the preprocess is called rather than rendered.** Core hides a source
 * its toolkit cannot style (`#access` FALSE) and svg_image is what shows it
 * again. neo_image does not depend on svg_image, so a render here would print
 * nothing either way; the variables are the contract. The registry assertion
 * is what says the hook is really reached.
 */
#[Group('neo_image')]
final class SvgImageStyleDimensionsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'neo_settings',
    'neo_twig',
    'neo_image',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'image']);

    $style = ImageStyle::create(['name' => 'probe_scale', 'label' => 'Probe']);
    $style->addImageEffect([
      'id' => 'image_scale',
      'weight' => 0,
      'data' => ['width' => 220, 'height' => 220, 'upscale' => FALSE],
    ]);
    $style->save();
  }

  /**
   * The hook is registered on core's image_style theme hook.
   */
  public function testTheHookIsRegistered(): void {
    $registry = $this->container->get('theme.registry')->get();
    $this->assertContains(
      'neo_image_preprocess_image_style',
      $registry['image_style']['preprocess functions'] ?? []
    );
  }

  /**
   * A viewBox-only SVG takes its size through the style.
   *
   * 400x120 under a 220x220 scale is 220x66: the size the media library
   * preview now reserves.
   */
  public function testViewBoxSvgIsScaledByTheStyle(): void {
    $image = $this->preprocess($this->writeSvg('viewBox="0 0 400 120"'));
    $this->assertSame(220, $image['#width'] ?? NULL);
    $this->assertSame(66, $image['#height'] ?? NULL);
  }

  /**
   * A small SVG is not upscaled when the style says not to.
   */
  public function testSmallSvgKeepsItsSize(): void {
    $image = $this->preprocess($this->writeSvg('width="40px" height="20"'));
    $this->assertSame(40, $image['#width'] ?? NULL);
    $this->assertSame(20, $image['#height'] ?? NULL);
  }

  /**
   * An SVG whose size cannot be read stays unsized.
   */
  public function testUnmeasurableSvgStaysUnsized(): void {
    $image = $this->preprocess($this->writeSvg(''));
    $this->assertEmpty($image['#width'] ?? NULL);
    $this->assertEmpty($image['#height'] ?? NULL);
  }

  /**
   * A raster keeps exactly what core computed for it.
   */
  public function testRasterIsUntouched(): void {
    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      'public://raster.png',
      FileExists::Replace
    );
    $variables = $this->variables('public://raster.png', 40, 20);
    $this->container->get(ImageThemeHooks::class)->preprocessImageStyle($variables);
    $core = $variables['image'];
    neo_image_preprocess_image_style($variables);
    $this->assertSame($core, $variables['image']);
  }

  /**
   * The measurement reads attributes first and the viewBox for the rest.
   */
  public function testSvgDimensions(): void {
    $cases = [
      'viewBox only' => ['viewBox="0 0 400 120"', [400, 120]],
      'comma viewBox' => ['viewBox="0,0,300,90"', [300, 90]],
      'absolute attributes win' => ['width="200px" height="60" viewBox="0 0 400 120"', [200, 60]],
      'relative defers to viewBox' => ['width="100%" height="100%" viewBox="0 0 300 90"', [300, 90]],
      'one attribute scales viewBox' => ['width="200" viewBox="0 0 400 120"', [200, 60]],
      'height scales viewBox' => ['height="60" viewBox="0 0 400 120"', [200, 60]],
      'fractional' => ['viewBox="0 0 100.4 20.6"', [100, 21]],
    ];
    foreach ($cases as $case => [$attributes, [$width, $height]]) {
      $this->assertSame(
        ['width' => $width, 'height' => $height],
        NeoImageUtility::svgDimensions($this->writeSvg($attributes)),
        $case
      );
    }

    foreach ([
      'no size' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
      'zero viewBox' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 0 10"></svg>',
      'relative only' => '<svg xmlns="http://www.w3.org/2000/svg" width="50%" height="2em"></svg>',
      'not xml' => 'this is not an svg',
    ] as $case => $markup) {
      $uri = 'public://bad-' . md5($case) . '.svg';
      \file_put_contents($uri, $markup);
      $this->assertSame([], NeoImageUtility::svgDimensions($uri), $case);
    }
    $this->assertSame([], NeoImageUtility::svgDimensions('public://missing.svg'));
  }

  /**
   * Runs core's image_style preprocess and then this module's.
   *
   * @param string $uri
   *   The source URI.
   *
   * @return array
   *   The image render array the template receives.
   */
  private function preprocess(string $uri): array {
    $variables = $this->variables($uri);
    $this->container->get(ImageThemeHooks::class)->preprocessImageStyle($variables);
    neo_image_preprocess_image_style($variables);
    return $variables['image'];
  }

  /**
   * The variables core's image_style theme hook starts from.
   */
  private function variables(string $uri, ?int $width = NULL, ?int $height = NULL): array {
    return [
      'style_name' => 'probe_scale',
      'uri' => $uri,
      'width' => $width,
      'height' => $height,
      'alt' => '',
      'title' => NULL,
      'attributes' => [],
    ];
  }

  /**
   * Writes an SVG whose root element carries the given attributes.
   */
  private function writeSvg(string $attributes): string {
    $uri = 'public://probe-' . md5($attributes) . '.svg';
    \file_put_contents($uri, '<svg xmlns="http://www.w3.org/2000/svg" ' . $attributes . '></svg>');
    return $uri;
  }

}
