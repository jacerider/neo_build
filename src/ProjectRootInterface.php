<?php

declare(strict_types=1);

namespace Drupal\neo_build;

/**
 * Resolves the project root and the docroot prepare writes against.
 *
 * Replaceable so a kernel test can point prepare at a temporary directory.
 */
interface ProjectRootInterface {

  /**
   * Gets the project root: the directory holding composer.json.
   *
   * @return string
   *   The absolute project root, without a trailing slash.
   *
   * @throws \RuntimeException
   *   When no project root can be found.
   */
  public function getRoot(): string;

  /**
   * Gets the docroot, relative to the project root.
   *
   * @return string
   *   The docroot with a trailing slash, e.g. "web/".
   */
  public function getDocRoot(): string;

}
