<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Component\Utility\NestedArray;

/**
 * Resolves the project root from the app root and Composer's scaffold config.
 *
 * The project root is the app root itself or its parent, whichever holds
 * composer.json; the docroot is Composer's drupal-scaffold web-root. Failing to
 * find a root is an explicit failure, not a FALSE where a string was promised.
 */
final class ProjectRoot implements ProjectRootInterface {

  /**
   * The resolved project root.
   */
  private ?string $root = NULL;

  /**
   * Constructs a ProjectRoot.
   *
   * @param string $appRoot
   *   The app root.
   */
  public function __construct(
    private readonly string $appRoot,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getRoot(): string {
    if ($this->root === NULL) {
      foreach ([$this->appRoot, $this->appRoot . '/..'] as $candidate) {
        if (file_exists($candidate . '/composer.json')) {
          $real = realpath($candidate);
          if ($real !== FALSE) {
            $this->root = $real;
            break;
          }
        }
      }
      if ($this->root === NULL) {
        throw new \RuntimeException(sprintf('Could not find a project root: no composer.json in %s or its parent.', $this->appRoot));
      }
    }
    return $this->root;
  }

  /**
   * {@inheritdoc}
   */
  public function getDocRoot(): string {
    $composer = json_decode((string) file_get_contents($this->getRoot() . '/composer.json'), TRUE);
    $webRoot = is_array($composer)
      ? NestedArray::getValue($composer, ['extra', 'drupal-scaffold', 'locations', 'web-root'])
      : NULL;
    return str_replace('./', '', $webRoot ?? '/') . '/';
  }

}
