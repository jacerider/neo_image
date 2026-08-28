<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImageStyle;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the one derivative ensure the two URL entry points share.
 *
 * The **derivative ensure** is *build the derivative URI, write the derivative
 * if it is not already on disk*. Both URL **entry points** carried a verbatim
 * copy of it; it is now one private helper that both call, and it is the only
 * thing they share.
 *
 * They do **not** collapse into one method, and `docs/adr/0011` is why. This
 * module calls every URI that is not on the public stream "external",
 * `private://` included, and the URI entry point returns its argument
 * unchanged when that predicate is TRUE. A delegating entity entry point would
 * therefore hand back a raw `private://` string for every private file — not a
 * URL, not routable, not an image — where today it builds the image-style URL
 * core's private-derivative route depends on. This class pins that difference:
 * a future collapse fails, and anyone who *intends* to fix the URI entry
 * point's private-file behaviour edits the assertion deliberately, which is
 * the signal the record asks for.
 *
 * **Why kernel and not unit.** Every subject is a real file on a real stream,
 * written through a real image toolkit; the private case additionally needs a
 * private file path in settings, which is a kernel test's ordinary business.
 */
#[Group('neo_image')]
final class UrlEntryPointDerivativeEnsureTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'neo_settings',
    'neo_twig',
    'neo_image',
  ];

  /**
   * The public file every fixture points at.
   */
  private FileInterface $file;

  /**
   * The name of the image media type's source field.
   */
  private string $imageSourceField;

  /**
   * {@inheritdoc}
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    // A private stream to resolve against. Without a path in settings core
    // registers no private stream wrapper at all, and the pin below would be
    // asserting the absence of a scheme rather than the behaviour of one.
    mkdir($this->siteDirectory . '/private', 0775);
    $this->setSetting('file_private_path', $this->siteDirectory . '/private');
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Core registers this only when the setting was already present as the
    // container was built. Registering it here as well is what core's own
    // file tests do, and it makes the fixture independent of that ordering.
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
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);

    $imageType = $this->createMediaType('image', ['id' => 'image']);
    $this->imageSourceField = $imageType->getSource()->getConfiguration()['source_field'];

    $this->file = $this->createFileFixture('public://image-test.png');
  }

  /**
   * It writes the derivative from both entry points, through one shared step.
   *
   * Acceptance criterion: it writes the derivative from both url entry points
   * when asked to ensure, and leaves an existing derivative alone.
   *
   * Both halves are asserted here because sharing the step *is* the change.
   * The behaviour half alone passes against two verbatim copies — which is the
   * state this ticket exists to end — so the seam is asserted as well: exactly
   * one `createDerivative()` call survives in the class, it lives in a private
   * method, and both entry points reach it. Read as source rather than
   * behaviour because "written once" is not observable by calling anything.
   */
  public function testItWritesTheDerivativeFromBothUrlEntryPointsThroughOneSharedEnsure(): void {
    $media = $this->createImageMedia();
    $style = new NeoImageStyle(['scale' => [100]]);
    $uri = $this->file->getFileUri();
    $derivative = $style->getImageStyle()->buildUri($uri);

    // Nothing is written unless the flag asks for it, from either entry point.
    $style->toUrlFromUri($uri);
    $style->toUrlFromEntity($media);
    $this->assertFileDoesNotExist($derivative, 'Neither entry point writes a derivative unasked.');

    // The URI entry point writes it.
    $style->toUrlFromUri($uri, TRUE);
    $this->assertFileExists($derivative, 'The uri entry point wrote the derivative.');

    // The entity entry point writes the same one.
    $this->container->get('file_system')->delete($derivative);
    $this->assertFileDoesNotExist($derivative);
    $style->toUrlFromEntity($media, TRUE);
    $this->assertFileExists($derivative, 'The entity entry point wrote the derivative.');

    // An existing derivative is left alone. A sentinel rather than an mtime:
    // a rewrite within the same second would leave an mtime assertion green.
    file_put_contents($derivative, 'sentinel');
    $style->toUrlFromUri($uri, TRUE);
    $this->assertSame('sentinel', file_get_contents($derivative), 'The uri entry point left the existing derivative alone.');
    $style->toUrlFromEntity($media, TRUE);
    $this->assertSame('sentinel', file_get_contents($derivative), 'The entity entry point left the existing derivative alone.');

    // The seam: one ensure, shared.
    $source = $this->classSource();
    $this->assertSame(
      1,
      substr_count($source, 'createDerivative('),
      'The derivative is written from exactly one place in the class.'
    );

    $shared = $this->soleEnsureMethod();
    $this->assertTrue(
      (new \ReflectionMethod(NeoImageStyle::class, $shared))->isPrivate(),
      "{$shared}() is private: the entry points share a step, not a second public way in."
    );

    foreach (['toUrlFromUri', 'toUrlFromEntity'] as $entryPoint) {
      $body = $this->methodSource($entryPoint);
      $this->assertStringContainsString(
        '$this->' . $shared . '(',
        $body,
        "{$entryPoint}() reaches the shared ensure."
      );
      $this->assertStringNotContainsString(
        'buildUri(',
        $body,
        "{$entryPoint}() keeps no private copy of the ensure."
      );
    }
  }

  /**
   * A private file: an image-style url one side, the raw uri the other.
   *
   * Acceptance criterion: it answers an image-style url from the entity url
   * entry point for a private file, and the raw uri from the uri entry point.
   *
   * The pin `docs/adr/0011` asks for, and the reason the two entry points do
   * not collapse into one. `isExternalUri()` answers TRUE for every URI that
   * is not on the public stream, `private://` among them, so the URI entry
   * point short-circuits and answers its argument. The entity entry point
   * never consults the predicate: it builds the image-style URL that core's
   * `image.style_private` route delivers private derivatives through.
   *
   * A future collapse of the entity entry point into the URI one fails here.
   * Anyone who *intends* to fix the URI entry point's private-file behaviour
   * is editing this assertion deliberately, and should say so out loud — that
   * is the third alternative the record weighed, not a test that broke.
   */
  public function testPrivateFileGetsStyleUrlFromEntityAndRawUriFromUri(): void {
    $private = $this->createFileFixture('private://private-image-test.png');
    $media = $this->createImageMedia($private);
    $style = new NeoImageStyle(['scale' => [100]]);
    $uri = $private->getFileUri();

    $this->assertSame(
      $private->id(),
      $media->get('thumbnail')->target_id,
      'The subject resolves to the private file.'
    );
    $this->assertTrue(
      NeoImageStyle::isExternalUri($uri),
      'This module calls a private URI external, which is what the two entry points differ over.'
    );

    // The entity entry point builds the image-style URL, unconditionally.
    $entityUrl = $style->toUrlFromEntity($media);
    $this->assertNotSame($uri, $entityUrl, 'The entity entry point does not hand back the raw stream URI.');
    $this->assertStringContainsString(
      '/styles/' . $style->getImageStyleName() . '/private/',
      $entityUrl,
      'The entity entry point answers an image-style url for a private file.'
    );
    $this->assertSame(
      $style->getImageStyle()->buildUrl($uri),
      $entityUrl,
      'It is the image style\'s own url, for the resolved file.'
    );
    $this->assertSame(
      $entityUrl,
      $style->toUrlFromEntity($private),
      'A bare private file entity answers the same.'
    );

    // The URI entry point answers its argument, unchanged.
    $this->assertSame($uri, $style->toUrlFromUri($uri), 'The uri entry point answers the raw uri.');
    $this->assertSame($uri, $style->toUrlFromUri($uri, TRUE), 'Even when asked to ensure, because it never gets that far.');
  }

  /**
   * A public file answers the same url through both entry points.
   *
   * Acceptance criterion: it answers the same url as before for a public file
   * through both entry points.
   *
   * The blast radius of the extraction, stated. A public file is where the two
   * entry points agree, and nothing about pulling the **derivative ensure**
   * out of both is allowed to move that answer — with the flag or without it.
   *
   * The `/sites/default/files/` rewrite is pinned here rather than left
   * implicit. It sits on the line above the ensure block in both methods, it
   * is a separate open candidate, and the ticket says to leave it: a diff that
   * folded it in while extracting the ensure would be a path fix wearing a
   * seam change's commit message.
   */
  public function testPublicFileAnswersTheSameUrlThroughBothEntryPoints(): void {
    $media = $this->createImageMedia();
    $style = new NeoImageStyle(['scale' => [100]]);
    $uri = $this->file->getFileUri();
    $expected = $style->getImageStyle()->buildUrl($uri);

    $this->assertStringContainsString('/styles/' . $style->getImageStyleName() . '/public/', $expected);
    $this->assertSame($expected, $style->toUrlFromUri($uri), 'The uri entry point answers the image style url.');
    $this->assertSame($expected, $style->toUrlFromEntity($media), 'So does the entity entry point, for a media.');
    $this->assertSame($expected, $style->toUrlFromEntity($this->file), 'And for a bare file.');

    // The public-path rewrite both methods carry, untouched by this ticket.
    $this->assertSame(
      $expected,
      $style->toUrlFromUri('/sites/default/files/image-test.png'),
      'The uri entry point still rewrites a public file path before styling it.'
    );

    // Asking for the derivative changes what is on disk, never the answer.
    $this->assertSame($expected, $style->toUrlFromUri($uri, TRUE), 'Ensuring does not move the uri entry point\'s answer.');
    $this->assertSame($expected, $style->toUrlFromEntity($media, TRUE), 'Nor the entity entry point\'s.');
  }

  /**
   * The media url entry point is deprecated in its docblock and nowhere else.
   *
   * Acceptance criterion: it carries a docblock deprecation on the media url
   * entry point naming its replacement, with no trigger_error and no change to
   * what that method returns or throws.
   *
   * It is a strict subset of `toUrlFromEntity()` — same resolution, narrower
   * parameter — with no caller in this site's packages, themes or Twig, and it
   * is a URL entry point wearing a factory's **failure contract**: it throws
   * where its neighbour answers `'#'`. The tag is how the fourth behaviour is
   * retired, by label rather than by edit, so the throw and the answer are
   * asserted here unchanged alongside it.
   *
   * No `@trigger_error`, matching `docs/adr/0010`: the signal reaches a reader
   * and static analysis without putting a log line in front of an operator who
   * did not ask for the move. Nothing in this site calls it, so the tag plants
   * no finding in any repository.
   */
  public function testTheMediaUrlEntryPointIsDeprecatedInItsDocblockAndNowhereElse(): void {
    $method = new \ReflectionMethod(NeoImageStyle::class, 'buildUrlForMedia');
    $docblock = $method->getDocComment();
    $this->assertIsString($docblock, 'The media url entry point still carries a docblock.');

    $this->assertMatchesRegularExpression(
      '/@deprecated in neo_image:\d+\.\d+\.\d+ and is removed from neo_image:\d+\.0\.0\./',
      preg_replace('/\s+/', ' ', $docblock),
      'The tag states a major-version removal in the fleet\'s standard format.'
    );
    $this->assertStringContainsString(
      'Drupal\neo_image\NeoImageStyle::toUrlFromEntity()',
      $docblock,
      'The tag names the replacement.'
    );
    $this->assertStringNotContainsString(
      'trigger_error',
      $this->methodSource('buildUrlForMedia'),
      'Nothing about the deprecation fires at runtime.'
    );

    // Behaviour, unchanged. It still answers the image style's url for the
    // resolved file, and it still throws where a URL entry point would answer
    // '#'.
    $style = new NeoImageStyle(['scale' => [100]]);
    $media = $this->createImageMedia();
    // Asserting that a deprecated method still answers what it answered is
    // what this test is for, so its own two calls are exempt from the rule the
    // tag switches on.
    // @phpstan-ignore method.deprecated
    $answered = $style->buildUrlForMedia($media);
    $this->assertSame(
      $style->getImageStyle()->buildUrl($this->file->getFileUri()),
      $answered,
      'It answers what it answered before.'
    );

    $gone = $this->createMediaWithMissingThumbnail();
    $this->expectException(\InvalidArgumentException::class);
    // @phpstan-ignore method.deprecated
    $style->buildUrlForMedia($gone);
  }

  /**
   * Reads `NeoImageStyle` as written.
   *
   * @return string
   *   The file, as written.
   */
  private function classSource(): string {
    $source = file_get_contents((new \ReflectionClass(NeoImageStyle::class))->getFileName());
    $this->assertIsString($source);
    return $source;
  }

  /**
   * Reads one method of `NeoImageStyle` as written, docblock excluded.
   *
   * @param string $method
   *   The method to read.
   *
   * @return string
   *   The declaration and body, as written.
   */
  private function methodSource(string $method): string {
    $reflection = new \ReflectionMethod(NeoImageStyle::class, $method);
    $lines = file($reflection->getFileName());
    return implode('', array_slice(
      $lines,
      $reflection->getStartLine() - 1,
      $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
  }

  /**
   * Names the one method that writes a derivative.
   *
   * @return string
   *   The method's name.
   */
  private function soleEnsureMethod(): string {
    $found = [];
    foreach ((new \ReflectionClass(NeoImageStyle::class))->getMethods() as $method) {
      if ($method->getDeclaringClass()->getName() !== NeoImageStyle::class) {
        continue;
      }
      if (str_contains($this->methodSource($method->getName()), 'createDerivative(')) {
        $found[] = $method->getName();
      }
    }
    $this->assertCount(1, $found, 'Exactly one method writes a derivative: ' . implode(', ', $found ?: ['none']));
    return $found[0];
  }

  /**
   * Creates an image media item pointing at a file.
   *
   * @param \Drupal\file\FileInterface|null $file
   *   The file to point at. Defaults to the shared public fixture.
   *
   * @return \Drupal\media\MediaInterface
   *   The saved media item.
   */
  private function createImageMedia(?FileInterface $file = NULL): MediaInterface {
    $file = $file ?? $this->file;
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Image media',
      $this->imageSourceField => [
        'target_id' => $file->id(),
        'alt' => 'Authored alt',
      ],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Creates a media item whose thumbnail reference resolves to nothing.
   *
   * The file entity is what goes. The media keeps the reference it was saved
   * with and the reference no longer loads, which is the shape a missing file
   * actually has.
   */
  private function createMediaWithMissingThumbnail(): MediaInterface {
    $doomed = $this->createFileFixture('public://gone.png');
    $media = $this->createImageMedia($doomed);
    $id = $media->id();
    $doomed->delete();

    $storage = $this->container->get('entity_type.manager')->getStorage('media');
    $storage->resetCache([$id]);
    $reloaded = $storage->load($id);
    \assert($reloaded instanceof MediaInterface);
    return $reloaded;
  }

  /**
   * Copies core's test image to the given URI and saves a file entity for it.
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
