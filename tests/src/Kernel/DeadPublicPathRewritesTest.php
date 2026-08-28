<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the two **public-path rewrites** that could never fire, once removed.
 *
 * Three of the module's five rewrites feed `isExternalUri()` and are the fix
 * `PublicPathRewriteTest` pins. The other two are removals:
 *
 * - The **preprocess** recomputed the stream URI it had already computed
 *   twenty-six lines earlier, against a hardcoded `sites/default/files`, and
 *   assigned that to the theme variable. The assignment stays and now carries
 *   the URI the function actually uses; nothing after it in the function reads
 *   the variable and neither template references it.
 * - The **entity URL entry point** rewrote a file entity's URI. A file entity's
 *   URI is a stream URI — `public://`, `private://`, `s3://` — so that line has
 *   never had anything to match, and it is deleted rather than routed through
 *   the helper.
 *
 * **These are pins on a tidy-up, so most of them are green before the change.**
 * Both removals sit on paths that already work, so what the assertions state is
 * what must *not* move: the same image-style URL from the entity entry point
 * for a public file and for a private one, and the same single-style render for
 * a public-files web path. Two assertions do move, and both are red first — the
 * theme variable, which under a non-default public path was handed back a raw
 * web path rather than a stream URI, and the source seam, which is how "the
 * module contains the rewrite in exactly one place" is stated at all.
 *
 * **Why kernel.** A kernel test runs under a **non-default** public path on
 * every run, which is the configuration under which the hardcoded rewrites are
 * visibly inert; the private case additionally needs a private file path in
 * settings.
 */
#[Group('neo_image')]
final class DeadPublicPathRewritesTest extends KernelTestBase {

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
    // `neo-image-style.html.twig` uses filters `neo_twig` registers, and Twig
    // parses the whole template whichever branch renders.
    'neo_twig',
    'neo_image',
  ];

  /**
   * The public file the entity entry point resolves to.
   */
  private FileInterface $file;

  /**
   * The fixture image, as the root-relative web path a template hands over.
   */
  private string $webPath;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // A private stream to resolve against. Without a path in settings core
    // registers no private stream wrapper at all, and the private pin below
    // would be asserting the absence of a scheme rather than the behaviour of
    // one.
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Core registers this only when the setting was already present as the
    // container was built. Registering it here as well is what core's own file
    // tests do, and it makes the fixture independent of that ordering.
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', 'file_usage');
    $this->installConfig(['field', 'system', 'image', 'file']);

    $this->file = $this->createFileFixture('public://image-test.png');
    $this->webPath = '/' . PublicStream::basePath() . '/image-test.png';

    $this->assertNotSame(
      'sites/default/files',
      PublicStream::basePath(),
      'The suite runs under a non-default public path, which is what makes a dead rewrite observable.'
    );
  }

  /**
   * The preprocess assigns a stream uri to the theme variable.
   *
   * Acceptance criterion: it assigns a stream uri to the theme variable under a
   * non-default public path.
   *
   * The one assertion in this class that moves. `uri` is declared by
   * `hook_theme()`, so a downstream `hook_preprocess_neo_image_style()` may
   * read it; under a non-default public path the hardcoded recomputation
   * matched nothing and handed that reader the raw web path it came in as. The
   * value the function itself uses throughout was a stream URI all along, which
   * is what the assignment now carries.
   *
   * The preprocess is called directly rather than through the theme system:
   * the subject is the state of `$variables` when the function returns, which
   * is exactly what a later preprocess sees and what the render pipeline would
   * otherwise consume before any assertion could reach it.
   */
  public function testPreprocessAssignsTheStreamUriToTheThemeVariable(): void {
    $variables = [
      'neoImageStyle' => new NeoImageStyle(['scale' => [100]]),
      'uri' => $this->webPath,
      'alt' => 'Fixture alt',
      'title' => NULL,
      'attributes' => [],
      'url' => NULL,
    ];

    template_preprocess_neo_image_style($variables);

    $this->assertArrayHasKey(
      '#style_name',
      $variables['image'],
      'The styleable branch was taken, which is the branch that assigns the theme variable.'
    );
    $this->assertSame(
      'public://image-test.png',
      $variables['uri'],
      'The theme variable holds the stream uri the function measured with, not the web path it was handed.'
    );
  }

  /**
   * The entity url entry point answers the same url for a public file.
   *
   * Acceptance criterion: it answers the same image-style url from the entity
   * url entry point for a public file.
   *
   * A regression pin, green before the change and after it. The deleted line
   * ran on `$file->getFileUri()`, which is a `public://` URI here, so it never
   * had anything to match and removing it cannot move this answer. Asserted
   * because "it cannot match" is an argument, and an argument is worth a test.
   */
  public function testEntityUrlEntryPointAnswersTheSameUrlForPublicFile(): void {
    $style = new NeoImageStyle(['scale' => [100]]);
    $uri = $this->file->getFileUri();
    $expected = $style->getImageStyle()->buildUrl($uri);

    $this->assertStringContainsString('/styles/' . $style->getImageStyleName() . '/public/', $expected);
    $this->assertSame(
      $expected,
      $style->toUrlFromEntity($this->file),
      'The entity entry point answers the image style url for a public file.'
    );
    $this->assertSame(
      $expected,
      $style->toUrlFromEntity($this->file, TRUE),
      'Ensuring the derivative does not move that answer either.'
    );
  }

  /**
   * The entity url entry point answers the same url for a private file.
   *
   * Acceptance criterion: it answers the same image-style url from the entity
   * url entry point for a private file.
   *
   * A regression pin on a pin. `neo-image-file-resolver` fixed this entry
   * point's private-file answer to the image-style URL core's
   * `image.style_private` route delivers derivatives through, and
   * `docs/adr/0011` records why it does not delegate to the URI entry point.
   * This ticket edits the statement between the resolution and the ensure that
   * plan touched, so the pin has to survive it: a `private://` URI contains no
   * public-files path and never did, whichever path the rewrite was written
   * against.
   */
  public function testEntityUrlEntryPointAnswersTheSameUrlForPrivateFile(): void {
    $private = $this->createFileFixture('private://private-image-test.png');
    $style = new NeoImageStyle(['scale' => [100]]);
    $uri = $private->getFileUri();

    $this->assertTrue(
      NeoImageStyle::isExternalUri($uri),
      'This module calls a private URI external, which is the asymmetry the pin protects.'
    );

    $answered = $style->toUrlFromEntity($private);
    $this->assertNotSame($uri, $answered, 'The entity entry point does not hand back the raw stream URI.');
    $this->assertStringContainsString(
      '/styles/' . $style->getImageStyleName() . '/private/',
      $answered,
      'It answers an image-style url for a private file.'
    );
    $this->assertSame(
      $style->getImageStyle()->buildUrl($uri),
      $answered,
      'It is the image style\'s own url, for the resolved file.'
    );
  }

  /**
   * The single-style render answers the same for a public-files web path.
   *
   * Acceptance criterion: it answers the same single-style render as before for
   * a public-files web path.
   *
   * A regression pin, green throughout. The theme variable this ticket stops
   * recomputing is not read again by the function and is referenced by neither
   * template, so the markup is expected to be byte-identical — which is the
   * claim, and the reason the removal is a tidy-up rather than a fix. It
   * overlaps `PublicPathRewriteTest`'s pin on purpose: that one guards the
   * measuring URI's adoption, this one guards the edit made below it.
   */
  public function testSingleStyleRenderAnswersTheSameForWebPath(): void {
    $style = new NeoImageStyle(['scale' => [100]]);

    $build = $style->toRenderableFromUri($this->webPath, 'Fixture alt');
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringNotContainsString('<picture', $markup, 'A single-style render is one img, not a picture.');
    $this->assertStringContainsString('<img', $markup);
    $this->assertStringContainsString(
      '/styles/' . $style->getImageStyleName() . '/public/',
      $markup,
      'It points at the derivative.'
    );
    $this->assertStringContainsString('width="100"', $markup, 'Measured through the image toolkit, at the style\'s width.');
    $this->assertStringContainsString('alt="Fixture alt"', $markup);
  }

  /**
   * No code in the module names core's default public path any more.
   *
   * Not an acceptance criterion of its own but the only way to state the
   * ticket's outcome — "the module contains the rewrite in exactly one place" —
   * at all. Both removals sit on paths that already work, so no behavioural
   * assertion in this class fails if the two dead lines simply stay where they
   * are. Read as source rather than behaviour for that reason, the way
   * `UrlEntryPointDerivativeEnsureTest` reads the sole derivative ensure.
   *
   * Comments are stripped before the search: `rewritePublicPath()`'s docblock
   * names the hardcoded default it replaced, and explaining the mistake is not
   * making it.
   */
  public function testNoCodeNamesCoreDefaultPublicPath(): void {
    $found = [];
    foreach ($this->packageSourceFiles() as $file) {
      foreach (explode("\n", $this->codeWithoutComments($file)) as $line) {
        if (str_contains($line, 'sites/default/files')) {
          $found[] = basename($file) . ': ' . trim($line);
        }
      }
    }

    $this->assertSame(
      [],
      $found,
      "The module derives the public files path rather than naming core's default:\n" . implode("\n", $found)
    );
  }

  /**
   * Lists the module's own PHP source, tests excluded.
   *
   * @return string[]
   *   Absolute paths, sorted.
   */
  private function packageSourceFiles(): array {
    $root = dirname((new \ReflectionClass(NeoImageStyle::class))->getFileName(), 2);
    $files = [$root . '/neo_image.module'];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * Reads a PHP file with its comments and docblocks removed.
   *
   * @param string $file
   *   The file to read.
   *
   * @return string
   *   The file's code, comments stripped.
   */
  private function codeWithoutComments(string $file): string {
    $source = file_get_contents($file);
    $this->assertIsString($source, $file . ' is readable.');
    $code = '';
    foreach (token_get_all($source) as $token) {
      if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], TRUE)) {
        continue;
      }
      $code .= \is_array($token) ? $token[1] : $token;
    }
    return $code;
  }

  /**
   * Copies core's test image to the given URI and saves a file entity for it.
   *
   * @param string $uri
   *   The destination URI.
   *
   * @return \Drupal\file\FileInterface
   *   The saved file entity.
   */
  private function createFileFixture(string $uri): FileInterface {
    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      $uri,
      FileExists::Replace
    );
    $file = File::create(['uri' => $uri]);
    $file->setPermanent();
    $file->save();
    return $file;
  }

}
