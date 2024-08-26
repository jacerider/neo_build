<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\neo_build\Exception\ManifestCouldNotBeLoadedException;
use Drupal\neo_build\Exception\ManifestNotFoundException;

/**
 * Object representing vite manifest.
 */
class Manifest {

  /**
   * Vite manifest.
   *
   * @var array
   */
  private $manifest;

  /**
   * Base URI.
   *
   * @var string
   */
  private $baseUri;

  /**
   * The docroot.
   *
   * @var string
   */
  private $docroot;

  /**
   * Constructs vite manifest object.
   *
   * @throws \InvalidArgumentException
   * @throws \Drupal\vite\Exception\ManifestNotFoundException
   * @throws \Drupal\vite\Exception\ManifestCouldNotBeLoadedException
   */
  public function __construct(array $manifestPaths, string $baseUri, string $docroot, array $scaffold = []) {
    $this->manifest = [];
    foreach ($manifestPaths as $manifestPath) {
      $realManifestPath = realpath($manifestPath);
      if ($realManifestPath === FALSE) {
        if (!$scaffold) {
          throw new ManifestNotFoundException("Manifest file was not found under path: $manifestPath");
        }
        $manifest = $scaffold;
      }
      else {
        $manifestContent = file_get_contents($realManifestPath);
        if ($manifestContent === FALSE) {
          throw new ManifestCouldNotBeLoadedException("Failed loading manifest: $manifestPath");
        }

        $manifest = json_decode($manifestContent, TRUE) + $scaffold;
        if ($manifest === NULL || !is_array($manifest)) {
          throw new ManifestCouldNotBeLoadedException("Failed loading manifest: $manifestPath");
        }
      }

      $this->manifest += $manifest;
    }

    if (!parse_url($baseUri)) {
      throw new \InvalidArgumentException("Failed to parse base uri: $baseUri");
    }

    $this->baseUri = $baseUri;
    $this->docroot = $docroot;
  }

  /**
   * Returns resolved path of given chunk.
   */
  public function getChunk(string $chunk, bool $prependBaseUri = TRUE): ?string {
    $chunk = $this->docroot . $this->baseUri . $chunk;
    if (!$this->chunkExists($chunk)) {
      return NULL;
    }
    return $this->getPath($this->manifest[$chunk]['file'], $prependBaseUri);
  }

  /**
   * Returns imports paths of given chunk.
   */
  public function getImports(string $chunk, bool $prependBaseUri = TRUE): array {
    return $this->getChunkPropertyPaths('imports', $chunk, $prependBaseUri);
  }

  /**
   * Returns styles paths of given chunk.
   */
  public function getStyles(string $chunk, bool $prependBaseUri = TRUE): array {
    return $this->getChunkPropertyPaths('css', $chunk, $prependBaseUri);
  }

  /**
   * Returns assets paths of given chunk.
   */
  public function getAssets(string $chunk, bool $prependBaseUri = TRUE): array {
    return $this->getChunkPropertyPaths('assets', $chunk, $prependBaseUri);
  }

  /**
   * Checks if chunk exists in the manifest.
   */
  private function chunkExists(string $chunk): bool {
    return isset($this->manifest[$chunk]);
  }

  /**
   * Resolves asset path.
   */
  private function getPath(string $assetPath, bool $prependBaseUri = TRUE): string {
    $assetPath = str_replace($this->baseUri, '', $assetPath);
    return ($prependBaseUri ? $this->baseUri : '') . $assetPath;
  }

  /**
   * Returns resolved paths of given chunk's property.
   */
  private function getChunkPropertyPaths(string $property, string $chunk, bool $prependBaseUri = TRUE): array {
    if (
      !$this->chunkExists($chunk)
      || empty($this->manifest[$chunk][$property])
      || !is_array($this->manifest[$chunk][$property])
    ) {
      return [];
    }

    return array_filter(array_map(
      fn($import) => $this->getChunk($import, $prependBaseUri),
      $this->manifest[$chunk][$property],
    ));
  }

}
