<?php

declare(strict_types=1);

namespace Drupal\neo_build\Generator;

use Drupal\neo_build\NeoBuildCollection;

/**
 * Renders one build artifact from the build collection.
 *
 * Prepare writes one file per artifact — neo.json, neo.tsconfig.json,
 * phpstan.neon, tailwind.neo.css — and each artifact has exactly one
 * generator. A generator is read-only over the collection: it may not add to,
 * remove from or reorder anything the collection holds, so the order the
 * generators run in can never change what any of them writes. Generators are
 * plain classes the caller constructs; they are not services and there is no
 * extension point.
 */
interface ArtifactGeneratorInterface {

  /**
   * Renders the artifact.
   *
   * @param \Drupal\neo_build\NeoBuildCollection $collection
   *   The build collection. Read, never written.
   *
   * @return \Drupal\neo_build\Generator\Artifact|null
   *   The artifact to write, or NULL when this generator has nothing to
   *   write for the collection as it stands. The caller decides whether that
   *   is worth a word.
   */
  public function generate(NeoBuildCollection $collection): ?Artifact;

}
