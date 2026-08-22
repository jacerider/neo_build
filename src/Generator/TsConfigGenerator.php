<?php

declare(strict_types=1);

namespace Drupal\neo_build\Generator;

use Drupal\neo_build\NeoBuildCollection;

/**
 * Generates neo.tsconfig.json, the TypeScript include list.
 *
 * The includes are neo_build's own typings first — the collection adds them
 * as it is constructed — then every TS entrypoint and typings directory in
 * the order the collection received them.
 */
final class TsConfigGenerator implements ArtifactGeneratorInterface {

  /**
   * The artifact's file name, relative to the project root.
   */
  public const FILENAME = 'neo.tsconfig.json';

  /**
   * {@inheritdoc}
   */
  public function generate(NeoBuildCollection $collection): ?Artifact {
    return new Artifact(
      $collection->getRoot() . self::FILENAME,
      (string) json_encode(['include' => $collection->getTsIncludes()], JSON_PRETTY_PRINT),
    );
  }

}
