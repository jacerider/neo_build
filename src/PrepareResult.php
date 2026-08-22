<?php

declare(strict_types=1);

namespace Drupal\neo_build;

/**
 * What a prepare produced: the artifacts it wrote and the notices it gathered.
 */
final class PrepareResult {

  /**
   * Absolute paths of the artifacts written, in the order written.
   *
   * @var string[]
   */
  private array $artifacts = [];

  /**
   * The notices, in the order they arose.
   *
   * @var \Drupal\neo_build\PrepareNotice[]
   */
  private array $notices = [];

  /**
   * Constructs a PrepareResult.
   *
   * @param string $scope
   *   The prepared scope id.
   * @param string $scopeLabel
   *   The scope's label.
   */
  public function __construct(
    private readonly string $scope,
    private readonly string $scopeLabel,
  ) {}

  /**
   * Gets the prepared scope id.
   *
   * @return string
   *   The scope id.
   */
  public function getScope(): string {
    return $this->scope;
  }

  /**
   * Gets the prepared scope's label.
   *
   * @return string
   *   The label.
   */
  public function getScopeLabel(): string {
    return $this->scopeLabel;
  }

  /**
   * Records a written artifact.
   *
   * @param string $path
   *   The artifact's absolute path.
   */
  public function addArtifact(string $path): void {
    $this->artifacts[] = $path;
  }

  /**
   * Gets the artifacts written.
   *
   * @return string[]
   *   Absolute paths, in the order written.
   */
  public function getArtifacts(): array {
    return $this->artifacts;
  }

  /**
   * Records a notice.
   *
   * @param \Drupal\neo_build\PrepareNotice $notice
   *   The notice.
   */
  public function addNotice(PrepareNotice $notice): void {
    $this->notices[] = $notice;
  }

  /**
   * Gets the notices, optionally of one type.
   *
   * @param string|null $type
   *   A PrepareNotice type constant, or NULL for all.
   *
   * @return \Drupal\neo_build\PrepareNotice[]
   *   The notices, in the order they arose.
   */
  public function getNotices(?string $type = NULL): array {
    if ($type === NULL) {
      return $this->notices;
    }
    return array_values(array_filter($this->notices, fn (PrepareNotice $notice): bool => $notice->getType() === $type));
  }

}
