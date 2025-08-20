<?php

namespace Drupal\neo_build;

use Drupal\neo_build\Exception\ManifestCouldNotBeLoadedException;
use Drupal\neo_build\Exception\ManifestNotFoundException;

/**
 * Defines an library object.
 *
 * This class does not implement the Serializable interface since problems
 * occurred when using the serialize method.
 *
 * @see https://bugs.php.net/bug.php?id=66052
 */
class NeoManifest {

  /**
   * The Vite manifest.
   *
   * @var array
   */
  private array $manifest;

  /**
   * The distribution path.
   *
   * @var string
   */
  private string $distPath;

  /**
   * Constructs a new NeoManifest object.
   *
   * @param string $manifestPath
   *   The path to the manifest file.
   * @param string $distPath
   *   The distribution path.
   */
  public function __construct(string $manifestPath, string $distPath) {
    if (!file_exists($manifestPath)) {
      throw new ManifestNotFoundException("Failed loading manifest: $manifestPath");
    }
    $manifestContent = file_get_contents($manifestPath);
    if (!$manifestContent) {
      throw new ManifestCouldNotBeLoadedException("Failed loading manifest: $manifestPath");
    }
    $this->manifest = json_decode($manifestContent, TRUE);
    if ($this->manifest === NULL || !is_array($this->manifest)) {
      throw new ManifestCouldNotBeLoadedException("Failed loading manifest: $manifestPath");
    }
    $this->distPath = $distPath;
  }

  /**
   * Gets an item from the manifest.
   *
   * @param string $path
   *   The path to the item.
   *
   * @return array|null
   *   The item data, or NULL if not found.
   */
  public function getItem(string $path): ?array {
    return $this->manifest[$path] ?? NULL;
  }

  /**
   * Gets the distribution path for a given item.
   *
   * @param string $path
   *   The path to the item.
   *
   * @return string|null
   *   The distribution path for the item, or NULL if not found.
   */
  public function getDistPath($path): ?string {
    $item = $this->getItem($path);
    if (!$item) {
      return NULL;
    }
    return $item['file'] ? '/' . $this->distPath . '/' . $item['file'] : NULL;
  }

}
