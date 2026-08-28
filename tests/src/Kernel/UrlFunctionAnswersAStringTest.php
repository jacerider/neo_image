<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Kernel;

use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\PublicStream;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\neo_image\NeoImageStyle;
use Drupal\neo_image\TwigExtension;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins `neo_image_style_url()` to answering a string in every case.
 *
 * The Twig URL function used to initialise its result to an empty array and
 * answer that array whenever the subject was neither a string nor an entity —
 * in practice whenever it was NULL, which is what an unset prop or an
 * undefined template variable arrives as. Every caller uses the answer as a
 * URL, so that answer printed as the word `Array`: this site has three hero
 * components that feed it straight into an inline `background-image`, and a
 * slide saved without an image rendered `background-image: url('Array')`.
 *
 * The answer on an unrecognised subject becomes `''`. **Nothing else moves**,
 * and the three tests after the first are here to say so rather than to
 * describe new behaviour:
 *
 * - a string subject still answers what the URI **entry point** answers;
 * - an entity subject still answers what the entity **entry point** answers;
 * - an entity with no **resolved file** still answers `'#'` from that entry
 *   point, *not* `''`. Those are two different states — "you handed me
 *   nothing" and "what you handed me resolves to no file" — and the
 *   **failure contract** that distinguishes them belongs to `NeoImageStyle`,
 *   not to this function.
 *
 * The unused `$alt` and `$title` parameters stay, accepted and ignored,
 * because a URL carries no alt. They are asserted here for the same reason
 * they stay: their position is the contract of every call site, the module
 * README and `neo_alchemist`'s generated per-prop Twig hints.
 *
 * **Why kernel.** Four of the five subjects reach an entity, a stream wrapper
 * or the public-files base path, and the expected answers are the image
 * style's own URLs built from them. Only the NULL case would run without a
 * container, and splitting it out would separate the case that changed from
 * the cases that must not.
 */
#[Group('neo_image')]
final class UrlFunctionAnswersAStringTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * The options every subject is styled under.
   */
  private const OPTIONS = ['scale' => [100]];

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
   * The public fixture file, and the file subject.
   */
  private FileInterface $file;

  /**
   * An image media pointing at the fixture file.
   */
  private MediaInterface $media;

  /**
   * The name of the image media type's source field.
   */
  private string $imageSourceField;

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
    $this->media = $this->createImageMedia();
  }

  /**
   * It answers an empty string, not an empty array, for what it cannot read.
   *
   * Acceptance criterion: it answers an empty string, not an empty array, for
   * a subject it does not recognise.
   *
   * NULL is asserted first and hardest. It is the one a template reaches
   * without doing anything wrong — an unset prop, an undefined variable — and
   * it is the subject behind every live `url('Array')` this change ends. The
   * others are here so the answer is the *shape's*, not NULL's.
   */
  public function testAnUnrecognisedSubjectAnswersAnEmptyString(): void {
    $answer = TwigExtension::renderImageStyleUrl(NULL, self::OPTIONS);
    $this->assertIsString($answer, 'A NULL subject answers a string.');
    $this->assertSame('', $answer, 'A NULL subject answers an empty string.');

    foreach ($this->unrecognisedSubjects() as $name => $subject) {
      $answer = TwigExtension::renderImageStyleUrl($subject, self::OPTIONS);
      $this->assertIsString($answer, $name . ' answers a string.');
      $this->assertSame('', $answer, $name . ' answers an empty string.');
    }

    // The options are not what decides it: an unrecognised subject answers the
    // same with no options at all.
    $this->assertSame('', TwigExtension::renderImageStyleUrl(NULL));
  }

  /**
   * It answers the image style's url for a uri string and for an entity.
   *
   * Acceptance criterion: it answers an image-style url for a uri string and
   * for an entity, unchanged from today.
   *
   * Characterisation. The recognised subjects are the half of this function
   * that does not move, and each is asserted against the **entry point** it
   * is meant to be answering from — `toUrlFromUri()` for the two strings,
   * `toUrlFromEntity()` for the two entities — rather than against a URL
   * spelled out here, so the pin follows the entry points if they are ever
   * re-derived.
   */
  public function testItAnswersAnImageStyleUrlForUriStringsAndEntities(): void {
    $style = new NeoImageStyle(self::OPTIONS);
    $expected = $style->getImageStyle()->buildUrl('public://image-test.png');
    $this->assertStringContainsString('/styles/' . $style->getImageStyleName() . '/public/', $expected);

    foreach ($this->recognisedSubjects() as $name => $subject) {
      $this->assertSame(
        $expected,
        TwigExtension::renderImageStyleUrl($subject, self::OPTIONS),
        $name . ' answers the image style url.'
      );
    }

    // A string this module calls external is answered unchanged, which is the
    // URI entry point's own behaviour and is not this function's to decide.
    $this->assertSame(
      'https://placehold.co/300x200.png',
      TwigExtension::renderImageStyleUrl('https://placehold.co/300x200.png')
    );
  }

  /**
   * An entity with no file still answers '#', not an empty string.
   *
   * Acceptance criterion: it still answers '#' for an entity with no file,
   * rather than an empty string.
   *
   * The two answers this function can give without a URL stay distinct. `''`
   * means the subject was not one this function reads; `'#'` means it was, and
   * resolved to no file. Collapsing them would throw away the only signal a
   * caller has, and the **failure contract** that produces `'#'` is
   * `NeoImageStyle`'s, reached through here untouched.
   */
  public function testAnEntityWithNoFileStillAnswersTheHash(): void {
    $gone = $this->createMediaWithMissingThumbnail();

    $answer = TwigExtension::renderImageStyleUrl($gone, self::OPTIONS);
    $this->assertSame('#', $answer, 'A media resolving to no file answers the hash.');
    $this->assertNotSame('', $answer, 'It is not the unrecognised-subject answer.');
    $this->assertSame(
      (new NeoImageStyle(self::OPTIONS))->toUrlFromEntity($gone),
      $answer,
      'It is the entity entry point\'s answer, passed through.'
    );

    // The distinction, stated as the thing it is: two recognisable states.
    $this->assertNotSame(
      TwigExtension::renderImageStyleUrl(NULL, self::OPTIONS),
      $answer,
      'A subject that resolves to no file is not the same state as no subject.'
    );
  }

  /**
   * It accepts an alt and a title and ignores both, whatever the subject.
   *
   * Acceptance criterion: it accepts and ignores an alt and a title argument
   * without changing what it answers.
   *
   * A URL carries no alt, so the two parameters exist only to keep the
   * argument order every call site, the README and the generated editor hints
   * encode. The forms asserted are the ones that actually arrive: a plain
   * string, a `|t` in a template — which is a stringable, not a string — and
   * the explicit NULL `neo_toolbar` passes.
   *
   * The unrecognised subject is in the grid deliberately. "Without changing
   * what it answers" has to name what that is, and for that subject what it
   * answers is `''`.
   */
  public function testItAcceptsAndIgnoresAltAndTitle(): void {
    $subjects = $this->recognisedSubjects() + [
      'a media with no file' => $this->createMediaWithMissingThumbnail(),
      'nothing' => NULL,
    ] + $this->unrecognisedSubjects();

    $altAndTitle = [
      'a string' => ['Alt text', 'Title text'],
      'a stringable' => [new TranslatableMarkup('Alt text'), new TranslatableMarkup('Title text')],
      'an explicit NULL' => [NULL, NULL],
      'an empty string' => ['', ''],
    ];

    foreach ($subjects as $subjectName => $subject) {
      $baseline = TwigExtension::renderImageStyleUrl($subject, self::OPTIONS);
      $this->assertIsString($baseline, $subjectName . ' answers a string with no alt.');

      foreach ($altAndTitle as $formName => [$alt, $title]) {
        $where = sprintf('%s, given %s', $subjectName, $formName);
        $answer = TwigExtension::renderImageStyleUrl($subject, self::OPTIONS, $alt, $title);
        $this->assertIsString($answer, $where . ': still a string.');
        $this->assertSame($baseline, $answer, $where . ': the answer did not move.');
      }
    }

    // The four expected answers, spelled out once, so the loop above is
    // comparing against something and not against itself.
    $url = (new NeoImageStyle(self::OPTIONS))->getImageStyle()->buildUrl('public://image-test.png');
    $this->assertSame($url, TwigExtension::renderImageStyleUrl($this->media, self::OPTIONS, 'Alt', 'Title'));
    $this->assertSame($url, TwigExtension::renderImageStyleUrl('public://image-test.png', self::OPTIONS, 'Alt', 'Title'));
    $this->assertSame('#', TwigExtension::renderImageStyleUrl($this->createMediaWithMissingThumbnail(), self::OPTIONS, 'Alt', 'Title'));
    $this->assertSame('', TwigExtension::renderImageStyleUrl(NULL, self::OPTIONS, 'Alt', 'Title'));
  }

  /**
   * The subjects this function reads, all resolving to the fixture file.
   *
   * @return array<string, mixed>
   *   Each subject, keyed by what it is.
   */
  private function recognisedSubjects(): array {
    return [
      'a public:// uri' => 'public://image-test.png',
      'a public-files web path' => '/' . PublicStream::basePath() . '/image-test.png',
      'an image media' => $this->media,
      'a file entity' => $this->file,
    ];
  }

  /**
   * The subjects this function does not read.
   *
   * NULL is not in here: it is asserted on its own, because it is the one a
   * template reaches without doing anything wrong.
   *
   * @return array<string, mixed>
   *   Each subject, keyed by what it is.
   */
  private function unrecognisedSubjects(): array {
    return [
      'an object of no relevant type' => new \stdClass(),
      'an integer' => 42,
      'an array' => ['public://image-test.png'],
      'FALSE' => FALSE,
    ];
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
   *
   * @return \Drupal\media\MediaInterface
   *   The media item, reloaded so nothing is answered from a static cache.
   */
  private function createMediaWithMissingThumbnail(): MediaInterface {
    $doomed = $this->createFileFixture('public://gone-' . uniqid() . '.png');
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
   *
   * @param string $uri
   *   The URI to copy the fixture to.
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
