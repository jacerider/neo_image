<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_image\Unit;

use Drupal\Core\StreamWrapper\LocalStream;

/**
 * A writable local stream wrapper over a real directory, for the flush tests.
 *
 * The **style flush** asks the stream wrapper manager for the writable
 * wrappers and then addresses every **derivative directory** through a
 * `scheme://styles/...` uri, so a mock of the manager alone is not enough: the
 * `file_exists()` guards in front of each deletion need those uris to resolve.
 * This registers one class under as many schemes as a test wants, each mapped
 * to its own temporary directory, so a flush can be watched across more than
 * one wrapper without a container, a site or a settings.php.
 *
 * It is deliberately a subclass of core's `LocalStream` rather than a
 * hand-written wrapper: every method PHP's stream API needs is already there
 * and already correct, and the four below are the whole difference between it
 * and the public files wrapper.
 */
final class FlushTestStream extends LocalStream {

  /**
   * The directory each registered scheme reads.
   *
   * Static because PHP constructs a wrapper instance per uri, so the scheme in
   * the uri is the only thing an instance knows about which test directory it
   * belongs to.
   *
   * @var array<string, string>
   */
  private static array $directories = [];

  /**
   * Registers a scheme backed by a real directory.
   *
   * @param string $scheme
   *   The scheme, without the `://`.
   * @param string $directory
   *   An existing directory the scheme's uris resolve inside.
   */
  public static function registerScheme(string $scheme, string $directory): void {
    self::$directories[$scheme] = $directory;
    stream_wrapper_register($scheme, static::class);
  }

  /**
   * Unregisters every scheme this class registered.
   */
  public static function unregisterSchemes(): void {
    foreach (array_keys(self::$directories) as $scheme) {
      stream_wrapper_unregister($scheme);
    }
    self::$directories = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDirectoryPath() {
    [$scheme] = explode('://', (string) $this->uri, 2);
    return self::$directories[$scheme] ?? '';
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Neo image flush test files';
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return 'Files under a temporary directory, for the style flush tests.';
  }

  /**
   * {@inheritdoc}
   */
  public function getExternalUrl() {
    return 'http://example.com/' . $this->getTarget();
  }

}
