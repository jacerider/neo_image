<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\Render\RenderContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves a template can call the three Twig names, and answers what they do.
 *
 * The three registered names are the module's whole template surface, and
 * thirteen kernel classes exercise the functions behind them by calling the
 * static methods directly. Not one goes through a template, so the one thing
 * the registration exists to make possible was the one thing untested: a
 * registration no template could reach would have satisfied all of them.
 *
 * **This class renders.** Every assertion here goes through the site's real
 * Twig environment — the tagged extension service, the environment Drupal
 * assembles, the answer printed through the renderer — so its failure mode is
 * *the template did not reach the function*.
 *
 * **The expected value is the direct call's answer, not literal markup.**
 * Every one of the three already has a class pinning what it answers, so this
 * class's subject is the path from a template to those answers rather than the
 * answers themselves. That is the shape `RenderDispatchEquivalenceTest`
 * established, and it is why this class needs no fixture grid of its own: a
 * changed derivative URL or a changed attribute moves both sides together and
 * says nothing, while a name Twig cannot resolve moves only one.
 *
 * **Nothing here asserts how the functions are registered.** No callable, no
 * method modifier, no compiled call form. What a template can call and what it
 * gets back is the claim; the performance reason behind the registration's
 * shape is documented where it is decided, in the extension's own docblock.
 *
 * **Why kernel.** A hand-built environment carrying the extension would prove
 * `getFunctions()` works in *an* environment. What was untested is that a
 * template on a Drupal site can call these names, and only the real container
 * answers that.
 *
 * **On the module list.** `neo-image.html.twig` pipes through
 * `neo_attributes`, a filter `neo_twig` registers, so the **responsive
 * render** cannot be printed without it and this class installs it exactly as
 * `RenderDispatchEquivalenceTest` does. That is a module the fixture genuinely
 * needs, which is the test a kernel class's module list has to pass — not a
 * module that happens to be installed wherever the suite is being run.
 *
 * @see \Drupal\Tests\neo_image\Kernel\TemplateReachesASubclassedExtensionTest
 * @see \Drupal\Tests\neo_image\Kernel\RenderDispatchEquivalenceTest
 */
#[Group('neo_image')]
final class TemplateAnswersTheDirectCallTest extends KernelTestBase {

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
    // registers. The responsive render cannot be printed without it.
    'neo_twig',
    'neo_image',
  ];

  /**
   * The subject every template and every direct call is handed.
   */
  private const SUBJECT = 'public://image-test.png';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installConfig(['system', 'image', 'file']);

    $this->container->get('file_system')->copy(
      $this->root . '/core/tests/fixtures/files/image-test.png',
      self::SUBJECT,
      FileExists::Replace
    );
  }

  /**
   * A template calling each of the three names answers what a direct call does.
   *
   * Acceptance criterion: a template calling each of the three registered
   * names answers what a direct call answers.
   *
   * Each cell is asserted twice. The template's answer must match the direct
   * call's, and it must not be empty — an unresolvable function name prints
   * nothing in Twig, and nothing matches nothing for a subject the module
   * declines to read, so the second assertion is what keeps the first from
   * passing vacuously.
   */
  public function testEveryRegisteredNameAnswersWhatTheDirectCallAnswers(): void {
    $cells = 0;
    foreach ($this->directCalls() as $name => $direct) {
      $options = ['size' => ['width' => 640, 'height' => 480]];
      $printed = $this->printTemplate($name, $options);

      $this->assertNotSame('', $printed, $name . ' printed nothing at all.');
      $this->assertSame($direct($options), $printed, $name);
      $cells++;
    }
    $this->assertSame(3, $cells, 'All three registered names were called from a template.');
  }

  /**
   * A template printing the responsive render answers what a direct call does.
   *
   * Acceptance criterion: a template calling each of the three registered
   * names answers what a direct call answers.
   *
   * Breakpoint options choose the other of the two render shapes, and it is
   * the shape with a template of its own — the `<picture>` element and the
   * filter behind its attributes. Printing it is the deepest the path from a
   * template to an answer goes, and the only cell here that would notice the
   * theme registration or the filter disappearing.
   *
   * The URL name is absent because it declares its options array and hands it
   * straight to a style, where a breakpoint key is not an effect: it answers
   * about breakpoint options what `UrlFunctionAnswersAStringTest` says it
   * does, and repeating that here would pin an answer rather than a path.
   *
   * **Each breakpoint names a width and no height**, which is a scale. A
   * width crossed with a height is a **contributed effect** — `focal_point`
   * provides the plugin — and printing one needs a module a checkout is not
   * guaranteed to have. The shape under test is the path from a template to
   * an answer, and a scale reaches every step of it.
   */
  public function testTheResponsiveRenderAnswersTheSameThroughItsTemplate(): void {
    $options = [
      'sm' => ['width' => 320],
      'lg' => ['width' => 960],
    ];

    $cells = 0;
    foreach (['neo_image', 'neo_image_style'] as $name) {
      $printed = $this->printTemplate($name, $options);

      $this->assertStringContainsString('<picture', $printed, $name . ' did not print the responsive render.');
      $this->assertSame($this->printRenderArray(TwigExtension::renderImage(self::SUBJECT, $options)), $printed, $name);
      $cells++;
    }
    $this->assertSame(2, $cells, 'Both render names printed the responsive shape.');
  }

  /**
   * The extension offers a template the three names it calls, and no fourth.
   *
   * Acceptance criterion: a template can call all three names, and the
   * extension registers no fourth.
   *
   * The names are the module's public template surface, so a fourth appearing
   * is a surface a site can come to depend on without anyone deciding to
   * publish it. This reads the names alone: what each one is wired to is the
   * extension's business, and pinning that is the assertion this class exists
   * to replace.
   */
  public function testItOffersThoseThreeNamesAndNoOther(): void {
    $names = [];
    foreach ($this->container->get('neo_image.twig_extension')->getFunctions() as $function) {
      $names[] = $function->getName();
    }
    sort($names);

    $this->assertSame(['neo_image', 'neo_image_style', 'neo_image_style_url'], $names);
  }

  /**
   * What each registered name answers when it is called directly.
   *
   * @return array<string, callable(array<string, mixed>): string>
   *   One callable per registered name, each answering the printed form of
   *   what the direct call answers, keyed by the name a template calls.
   */
  private function directCalls(): array {
    return [
      'neo_image' => fn(array $options): string => $this->printRenderArray(
        TwigExtension::renderImage(self::SUBJECT, $options)
      ),
      'neo_image_style' => fn(array $options): string => $this->printRenderArray(
        TwigExtension::renderImageStyle(self::SUBJECT, $options)
      ),
      'neo_image_style_url' => fn(array $options): string => TwigExtension::renderImageStyleUrl(
        self::SUBJECT,
        $options
      ),
    ];
  }

  /**
   * Prints `{{ name(subject, options) }}` through the real Twig environment.
   *
   * @param string $name
   *   The registered Twig function name to call.
   * @param array<string, mixed> $options
   *   The options to hand it.
   *
   * @return string
   *   Whatever the template printed.
   */
  private function printTemplate(string $name, array $options): string {
    $twig = $this->container->get('twig');
    $context = ['subject' => self::SUBJECT, 'options' => $options];
    $template = sprintf('{{ %s(subject, options) }}', $name);

    return (string) $this->container->get('renderer')->executeInRenderContext(
      new RenderContext(),
      static fn() => $twig->renderInline($template, $context)
    );
  }

  /**
   * Prints a render array the way printing it from a template would.
   *
   * @param array<string, mixed> $build
   *   The render array a direct call answered.
   *
   * @return string
   *   The rendered markup.
   */
  private function printRenderArray(array $build): string {
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

}
