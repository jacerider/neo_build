<?php

declare(strict_types=1);

namespace Drupal\neo_build\Generator;

/**
 * A rendered build artifact: its content and where it belongs on disk.
 */
final class Artifact {

  /**
   * Constructs an Artifact.
   *
   * @param string $destination
   *   The absolute path the artifact is written to.
   * @param string $content
   *   The artifact content.
   */
  public function __construct(
    private readonly string $destination,
    private readonly string $content,
  ) {}

  /**
   * Gets the absolute path the artifact is written to.
   *
   * @return string
   *   The destination path.
   */
  public function getDestination(): string {
    return $this->destination;
  }

  /**
   * Gets the artifact content.
   *
   * @return string
   *   The content.
   */
  public function getContent(): string {
    return $this->content;
  }

}
