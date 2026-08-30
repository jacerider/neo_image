<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\Element\FormElementBase;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\NeoImageStyleManager;
use Drupal\neo_image\Settings\ImageSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Closes the one config-sourced producer of an unsettable background.
 *
 * The image settings form is the only place a **settable value** rule cannot
 * reach on its own: its background element is a free-text field whose saved
 * value travels to `NeoImageStyle::exact()` through
 * `NeoImage::autoFromDimensions()`, and `exact()` answers a value the **id
 * grammar** cannot carry by storing none. That is the right answer at render
 * time — an unpadded image beats a white screen — but on its own it would leave
 * a site able to save a background that silently does nothing.
 *
 * So the element reports it instead. This is the seam that makes the drop safe
 * from both ends: a background an older site already saved degrades to no
 * background on render, the form names it the next time that page is saved, and
 * it cannot be stored again. No update hook is needed and none is written.
 *
 * The assertions drive core's own `#pattern` validator rather than restating
 * the regular expression, because what the criterion claims is that the *form*
 * reports the value. The pattern is only the mechanism, and it is core's.
 */
#[Group('neo_image')]
final class SettingsBackgroundPatternTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The form element builds translatable strings, and core's pattern
    // validator builds one more for the error it reports.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * It reports a background outside the id alphabet instead of saving it.
   *
   * Acceptance criterion: *the settings form reports a background outside the
   * id alphabet instead of saving it.*
   *
   * The value is run through `FormElementBase::validatePattern()`, which is the
   * callback core attaches to any element carrying a `#pattern`, so what this
   * asserts is the error a submission would actually collect rather than a
   * regular expression compared against itself.
   *
   * @param string $description
   *   What the row covers.
   * @param string $bg
   *   The background typed into the field.
   */
  #[DataProvider('providerBackgroundsTheFormMustReport')]
  public function testReportsBackgroundsOutsideTheIdAlphabet(string $description, string $bg): void {
    $this->assertNotSame([], $this->validateBackground($bg), $description . ': is reported on the form');
  }

  /**
   * Backgrounds `exact()` would refuse to store.
   *
   * @return \Generator
   *   Rows of description and typed value.
   */
  public static function providerBackgroundsTheFormMustReport(): \Generator {
    yield 'a colour function' => ['a colour function', 'rgb(255,0,0)'];
    yield 'a spaced hex' => ['a spaced hex', 'ff fff'];
    yield 'a hyphenated hex' => ['a hyphenated hex, whose hyphen is the grammar separator', 'ff-ffff'];
    yield 'an underscored hex' => ['an underscored hex, whose underscore joins properties', 'ff_ffff'];
    yield 'a lone hash' => ['a lone hash, which strips to nothing', '#'];
    yield 'several hashes' => ['nothing but hashes, which strip to nothing', '###'];
    yield 'a hashed colour function' => ['a hashed colour function', '#rgb(0,0,0)'];
  }

  /**
   * It still accepts every background the grammar can carry.
   *
   * The other half, and the reason the pattern tolerates a leading hash rather
   * than admitting one into the alphabet: it must refuse exactly what `exact()`
   * would drop and nothing more, and `exact()` strips the hash before it
   * checks. A site that typed one anyway has been saving a background that
   * works, and this element is not the place to take it away.
   *
   * The check is for what the id must be able to hold, never for a colour. A
   * named colour is inside the alphabet and is none of this form's business to
   * judge, and an empty field stays the way a site asks for a transparent
   * canvas.
   *
   * @param string $description
   *   What the row covers.
   * @param string $bg
   *   The background typed into the field.
   */
  #[DataProvider('providerBackgroundsTheFormMustAccept')]
  public function testStillAcceptsTheBackgroundsTheGrammarCanCarry(string $description, string $bg): void {
    $this->assertSame([], $this->validateBackground($bg), $description . ': is accepted');
  }

  /**
   * Backgrounds `exact()` stores.
   *
   * @return \Generator
   *   Rows of description and typed value.
   */
  public static function providerBackgroundsTheFormMustAccept(): \Generator {
    yield 'a bare hex' => ['a bare hex, which is what the field prefix asks for', 'ffffff'];
    yield 'a hashed hex' => ['a hashed hex, which the setter strips and stores', '#ffffff'];
    yield 'an uppercase hex' => ['an uppercase hex', 'FF0000'];
    yield 'a bare word' => ['a bare word, which is not this form\'s business to judge', 'red'];
    yield 'a bare alphanumeric word' => ['a bare alphanumeric word', 'gray50'];
    yield 'nothing at all' => ['an empty field, which is a transparent canvas', ''];
  }

  /**
   * Runs one typed value through the element's own validation.
   *
   * @param string $bg
   *   The background typed into the field.
   *
   * @return array
   *   The errors the submission would collect, keyed by element name.
   */
  private function validateBackground(string $bg): array {
    $element = $this->backgroundElement();
    $this->assertArrayHasKey('#pattern', $element, 'the background element carries a pattern');

    $element['#value'] = $bg;
    $element['#parents'] = ['dimensions', 'sm', 'settings', 'bg'];
    $form_state = new FormState();
    $complete_form = [];
    FormElementBase::validatePattern($element, $form_state, $complete_form);

    return $form_state->getErrors();
  }

  /**
   * Builds the settings form and answers its background element.
   *
   * @return array
   *   The background element.
   */
  private function backgroundElement(): array {
    $stream_wrapper_manager = $this->createMock(StreamWrapperManagerInterface::class);
    $stream_wrapper_manager->method('getWrappers')->willReturn([]);
    $style_manager = new NeoImageStyleManager(
      $stream_wrapper_manager,
      $this->createMock(FileSystemInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $settings = new ImageSettings(
      ['config' => [], 'variation' => [], 'variation_id' => NULL],
      'neo_image',
      ['id' => 'neo_image', 'configuration' => []],
      $this->createMock(MessengerInterface::class),
      $this->createMock(FormBuilderInterface::class),
      $style_manager,
    );

    // The breakpoints the dimension table carries are form-level configuration,
    // which nothing sets on a bare instance. One row is enough: the element is
    // built identically for each.
    (new \ReflectionMethod($settings, 'setFormConfigValues'))->invoke($settings, ['breakpoints' => ['sm']]);
    $form = (new \ReflectionMethod($settings, 'buildForm'))
      ->invoke($settings, ['#input_selector' => 'neo_image'], new FormState());

    return $form['dimensions']['sm']['settings']['bg'];
  }

}
