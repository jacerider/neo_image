<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileExists;
use Drupal\Core\Render\RenderContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\neo_image\Unit\DispatchProbeTwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * A template reaches a subclassed extension's own steps, not the base class's.
 *
 * `getFunctions()` registers against `static::class` rather than a spelled-out
 * class name, so a site that decorates or subclasses the extension service
 * still gets its own code called from every template. That guarantee used to
 * be asserted as a literal — read the callable, compare it to a class name —
 * which passed on a registration no template could reach. Here it is asserted
 * as what it buys: swap the extension service's class for a subclass, render a
 * template, and the subclass's steps are what ran.
 *
 * **This class has a container of its own, which is why it is a class of its
 * own.** `register()` runs for every method in a class, and the probe answers
 * an empty render array from both render shapes, so a class holding both this
 * criterion and the ones in `TemplateAnswersTheDirectCallTest` would have to
 * decide which container it wanted from inside `register()` — a test reaching
 * for its own method name to pick its own fixture. One container state per
 * class instead, each stated in its docblock.
 *
 * **What this does not cover, stated plainly.** Registering `[static::class,
 * 'renderImage']` and registering `[$this, 'renderImage']` both reach the
 * subclass here, because an instance callable binds `static::` to the
 * instance's class just as well. The difference between them is not
 * observable from a template at all: it is that Twig compiles a class-string
 * callable on a static method into a direct static call and an instance
 * callable into an extension lookup at every one of the roughly thirty live
 * call sites this package ships to. That is a performance property, no test
 * in this package measures it, and the reason for it lives in the extension's
 * own docblock where the decision is made. What this class does catch is the
 * other half — a class name spelled out in place of `static::class`, after
 * which no subclass wins anywhere.
 *
 * **The probe is the one that already exists.** It overrides both render
 * shapes to record which was chosen and to answer nothing, which is exactly
 * the observation this criterion needs and is why nothing here renders markup.
 * A second probe would be a second thing to keep in step with the dispatch.
 *
 * **On the module list.** Neither render shape gets past the probe, so no
 * template of this module's is ever parsed and the filter `neo_twig`
 * registers is not needed. It is not installed.
 *
 * @see \Drupal\Tests\neo_image\Kernel\TemplateAnswersTheDirectCallTest
 * @see \Drupal\Tests\neo_image\Unit\DispatchProbeTwigExtension
 */
#[Group('neo_image')]
final class TemplateReachesASubclassedExtensionTest extends KernelTestBase {

  /**
   * The subject every template is handed.
   */
  private const SUBJECT = 'public://image-test.png';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
    'image',
    'neo_settings',
    'neo_image',
  ];

  /**
   * {@inheritdoc}
   *
   * Unconditional, because it is the whole point of the class: every method
   * here renders through a Twig environment whose `neo_image` extension is
   * the probe subclass.
   */
  public function register(ContainerBuilder $container) {
    parent::register($container);
    $container->getDefinition('neo_image.twig_extension')
      ->setClass(DispatchProbeTwigExtension::class);
  }

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

    DispatchProbeTwigExtension::reset();
  }

  /**
   * A template reaches the subclass's own steps rather than the base class's.
   *
   * Acceptance criterion: a template reaches a subclassed extension's own
   * steps rather than the base class's, asserted in its own kernel class
   * whose container swaps the extension service's class.
   *
   * Both shapes are exercised, because a registration that reached the
   * subclass for one and the base class for the other would be a stranger
   * failure than either. The subject arrives at the recorded step unchanged,
   * which is how the record is tied to this call rather than to any call.
   */
  public function testOneTemplateReachesTheSubclassesOwnSteps(): void {
    $shapes = [
      'single-style' => ['size' => ['width' => 640, 'height' => 480]],
      'responsive' => ['sm' => ['width' => 320], 'lg' => ['width' => 960]],
    ];

    foreach ($shapes as $shape => $options) {
      DispatchProbeTwigExtension::reset();

      $printed = $this->printTemplate('neo_image', $options);

      $this->assertSame(
        [[$shape, self::SUBJECT]],
        DispatchProbeTwigExtension::$shapes,
        sprintf('The template reached the subclass for the %s shape.', $shape)
      );
      $this->assertSame(
        '',
        $printed,
        sprintf('And it printed the subclass\'s answer for the %s shape, not the base class\'s.', $shape)
      );
    }
  }

  /**
   * Both registered render names reach the subclass, not just the first.
   *
   * Acceptance criterion: a template reaches a subclassed extension's own
   * steps rather than the base class's.
   *
   * `neo_image` and `neo_image_style` are registered separately and are exact
   * synonyms, so a subclass winning at one and losing at the other is a state
   * the module has no name for. Asserting both is what stops the guarantee
   * from being true of whichever name happened to be tested.
   */
  public function testBothRenderNamesReachTheSubclass(): void {
    foreach (['neo_image', 'neo_image_style'] as $name) {
      DispatchProbeTwigExtension::reset();

      $this->printTemplate($name, ['size' => ['width' => 640, 'height' => 480]]);

      $this->assertSame(
        [['single-style', self::SUBJECT]],
        DispatchProbeTwigExtension::$shapes,
        $name . ' reached the subclass.'
      );
    }
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

}
