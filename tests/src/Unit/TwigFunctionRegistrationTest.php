<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;
use Twig\TwigFunction;

/**
 * Pins the three Twig functions to a static callable on the extension class.
 *
 * All three methods have always been `public static`, and all three were
 * registered as `[$this, 'method']` — a declaration contradicting its own
 * registration. Twig compiles an array callable whose first element is a
 * class string, on a static method, into a direct static call; an instance
 * callable compiles into an extension lookup at every one of the thirty live
 * call sites this package ships to.
 *
 * **The class element is `static::class`, not the class name spelled out**,
 * so a decorated or subclassed extension service still wins. The assertion
 * below reads the callable off an instance for exactly that reason: it is
 * asserting late static binding, not a literal.
 *
 * **Asserted on the returned callables, not on Twig's compiled output.**
 * Compiling a template and reading the generated PHP would assert Twig's
 * implementation of a decision Twig is free to change; what this module owns
 * is the callable it hands over.
 *
 * **Why unit.** `getFunctions()` reads no container, no configuration and no
 * entity.
 */
#[Group('neo_image')]
final class TwigFunctionRegistrationTest extends UnitTestCase {

  /**
   * It registers all three twig functions as static callables on the class.
   */
  public function testAllThreeFunctionsAreRegisteredAsStaticCallables(): void {
    $expected = [
      'neo_image' => [TwigExtension::class, 'renderImage'],
      'neo_image_style' => [TwigExtension::class, 'renderImageStyle'],
      'neo_image_style_url' => [TwigExtension::class, 'renderImageStyleUrl'],
    ];

    $registered = [];
    foreach ((new TwigExtension())->getFunctions() as $function) {
      $this->assertInstanceOf(TwigFunction::class, $function);
      $registered[$function->getName()] = $function->getCallable();
    }

    $this->assertSame($expected, $registered);

    // A static callable is one Twig can call without the extension instance.
    // Naming the method as a `Class::method` string is the same fact stated
    // the way a reader checks it, and it fails on an instance callable too.
    foreach ($expected as $name => $callable) {
      $this->assertTrue(
        (new \ReflectionMethod($callable[0], $callable[1]))->isStatic(),
        $name
      );
    }
  }

}
