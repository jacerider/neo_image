<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins one **render dispatch** to the answer both Twig names already gave.
 *
 * `neo_image()` and `neo_image_style()` are exact synonyms and are meant to
 * be. Before this ticket they were synonyms by mutual delegation — one handed
 * over when **breakpoint options** were absent, the other when they were
 * present — and after it they are synonyms because they hand the same
 * arguments to the same private step.
 *
 * **The dispatch is the unit under test, and every expectation here is
 * asserted of it first.** That is what makes these tests red against the
 * delegation cycle: the cycle has no dispatch to ask. What each test then
 * adds is that both public names answer what the dispatch answered, which is
 * the equivalence the ticket asks to survive.
 *
 * **The grid is the deliverable, not a side effect.** Seven subjects crossed
 * with three option shapes, through the dispatch and through both functions,
 * asserted identical. It is also the tripwire ADR 0013 asks for: a future
 * reader who makes `neo_image()` mean **responsive render** and
 * `neo_image_style()` mean **single-style render**, as their names and the
 * README suggest, is stopped by twenty-one failures rather than by a comment.
 *
 * **Why kernel.** Five of the seven subjects reach an entity, a stream
 * wrapper or the public-files base path, and the render arrays carry style
 * and image objects built from them. A unit test could only assert mocks.
 *
 * **The expected values are characterisation.** Every one of them states what
 * the mutual delegation answered, because the ticket's deliverable is that
 * nothing moved. What is new — and what these tests fail without — is that
 * one step answers them all.
 *
 * @see docs/adr/0013-the-two-twig-render-functions-answer-the-same-thing.md
 */
#[Group('neo_image')]
final class RenderDispatchEquivalenceTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * An external placeholder URL whose trailing size the swap can rewrite.
   */
  private const EXTERNAL_URL = 'https://placehold.co/300x200.png';

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
    // `neo-image.html.twig` uses `neo_attributes`, a filter `neo_twig`
    // registers. Twig parses the whole template whichever branch renders.
    'neo_twig',
    'neo_image',
  ];

  /**
   * The fixture file, and the subject a file entity contributes to the grid.
   */
  private FileInterface $file;

  /**
   * An image media pointing at the fixture file.
   */
  private MediaInterface $media;

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
    $sourceField = $imageType->getSource()->getConfiguration()['source_field'];

    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      'public://image-test.png',
      FileExists::Replace
    );
    $file = File::create(['uri' => 'public://image-test.png']);
    $file->setPermanent();
    $file->save();
    $this->file = $file;

    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Image media',
      $sourceField => [
        'target_id' => $file->id(),
        'alt' => 'Authored alt',
        'title' => 'Authored title',
      ],
    ]);
    $media->save();
    $this->media = $media;
  }

  /**
   * It answers the same render array from both functions, over the whole grid.
   *
   * Twenty-one cells, each asserted three ways: the dispatch's answer, and
   * both public names against it. Neither name may narrow to one shape and
   * neither may drift from the other, whatever the subject and whatever the
   * option shape.
   */
  public function testBothRenderFunctionsAnswerTheSameRenderArray(): void {
    $cells = 0;
    foreach ($this->subjects() as $subjectName => $subject) {
      foreach ($this->optionShapes() as $shapeName => $options) {
        $where = sprintf('%s crossed with %s options', $subjectName, $shapeName);
        $answer = $this->dispatch($subject, $options);
        $this->assertEquals(
          $answer,
          TwigExtension::renderImage($subject, $options),
          'neo_image: ' . $where
        );
        $this->assertEquals(
          $answer,
          TwigExtension::renderImageStyle($subject, $options),
          'neo_image_style: ' . $where
        );
        $cells++;
      }
    }
    // The grid is the assertion. A loop that silently stopped enumerating
    // would pass every comparison it made and cover nothing.
    $this->assertSame(21, $cells);
  }

  /**
   * It renders an external url carrying breakpoint options with no effects.
   *
   * The **responsive render** cannot build derivatives for a URL it does not
   * host, so it hands an external subject to the **single-style render** with
   * an *empty* options array: the breakpoint sizes are dropped rather than
   * carried across. That is today's answer at both entry points, and the
   * **placeholder swap** has already resolved the URL's trailing size from
   * those same options by the time they are dropped.
   */
  public function testAnExternalUrlWithBreakpointOptionsIsSingleStyleWithNoEffects(): void {
    foreach ($this->renderEntryPoints() as $name => $render) {
      $build = $render(self::EXTERNAL_URL, $this->optionShapes()['breakpoint-keyed']);

      $this->assertSame('neo_image_style', $build['#theme'], $name);
      $this->assertSame([], $build['#neoImageStyle']->getParameters(), $name);
      $this->assertSame('https://placehold.co/960x720.png', $build['#uri'], $name);
    }
  }

  /**
   * It answers an empty render array for a subject it does not recognise.
   */
  public function testAnUnrecognisedSubjectAnswersAnEmptyRenderArray(): void {
    foreach ($this->renderEntryPoints() as $name => $render) {
      foreach ($this->optionShapes() as $shapeName => $options) {
        $this->assertSame(
          [],
          $render(new \stdClass(), $options),
          sprintf('%s with %s options', $name, $shapeName)
        );
      }
    }
  }

  /**
   * The seven subjects the grid crosses.
   *
   * @return array<string, mixed>
   *   Each subject, keyed by what it is.
   */
  private function subjects(): array {
    return [
      'a public-files web path' => '/' . PublicStream::basePath() . '/image-test.png',
      'an external url' => self::EXTERNAL_URL,
      'a public:// uri' => 'public://image-test.png',
      'an image media' => $this->media,
      'a file entity' => $this->file,
      'nothing' => NULL,
      'none of those' => new \stdClass(),
    ];
  }

  /**
   * The three option shapes the grid crosses.
   *
   * @return array<string, array<string, mixed>>
   *   Each option shape, keyed by what it is.
   */
  private function optionShapes(): array {
    return [
      'effect-keyed' => ['size' => ['width' => 640, 'height' => 480]],
      'breakpoint-keyed' => [
        'sm' => ['width' => 320, 'height' => 240],
        'lg' => ['width' => 960, 'height' => 720],
      ],
      'empty' => [],
    ];
  }

  /**
   * The dispatch and the two Twig render functions, as callables.
   *
   * The dispatch comes first so a test that reaches this list says what it
   * is about the moment it runs: without one dispatch behind both names,
   * there is nothing to call.
   *
   * @return array<string, callable>
   *   The three render entry points, keyed by what they are.
   */
  private function renderEntryPoints(): array {
    return [
      'the render dispatch' => function (mixed $subject, mixed $options = []): mixed {
        return $this->dispatch($subject, $options);
      },
      'neo_image' => [TwigExtension::class, 'renderImage'],
      'neo_image_style' => [TwigExtension::class, 'renderImageStyle'],
    ];
  }

  /**
   * Calls the extension's private **render dispatch**.
   *
   * It is reached by reflection rather than promoted to public for the same
   * reason the **placeholder swap** is: the dispatch is what sits behind the
   * two registered names, not a fourth name, and widening a shared package's
   * surface for the test's convenience would be a contract change.
   *
   * @param mixed $subject
   *   The subject to render.
   * @param mixed $options
   *   The options to render it under.
   *
   * @return mixed
   *   What the dispatch answers.
   */
  private function dispatch(mixed $subject, mixed $options = []): mixed {
    $method = new \ReflectionMethod(TwigExtension::class, 'renderDispatch');
    $method->setAccessible(TRUE);
    return $method->invoke(NULL, $subject, $options);
  }

}
