<?php

namespace Drupal\neo_build;

use Drupal\Core\Extension\Extension;

/**
 * Defines an extension (file) object.
 *
 * This class does not implement the Serializable interface since problems
 * occurred when using the serialize method.
 *
 * @see https://bugs.php.net/bug.php?id=66052
 */
class NeoExtension {

  /**
   * The wrapped extension.
   *
   * @var \Drupal\Core\Extension\Extension
   */
  private Extension $extension;

  /**
   * The Neo-enabled libraries associated with the extension.
   *
   * @var \Drupal\neo_build\NeoLibrary[]
   */
  private array $libraries = [];

  /**
   * The extension info.
   *
   * @var array
   */
  private array $info;

  /**
   * The weight of the extension.
   *
   * @var int
   */
  private int $weight = 0;

  /**
   * Constructs a new NeoExtension object.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension to wrap.
   */
  public function __construct(Extension $extension) {
    $this->extension = $extension;
    $info = $extension->info;
    // Make sure we have Neo config.
    if (!isset($info['neo'])) {
      $info['neo'] = [];
    }
    // Ensure neo setting is an array.
    if (!is_array($info['neo'])) {
      $info['neo'] = [];
    }
    // Always set scope.
    if (!isset($info['neo']['scope'])) {
      $info['neo']['scope'] = $this->getType() === 'module'
        ? array_map(static fn (Scope $scope): string => $scope->value, Scope::cases())
        : [Scope::Front->value];
    }
    // Make sure scope is array.
    if (!is_array($info['neo']['scope'])) {
      $info['neo']['scope'] = [$info['neo']['scope']];
    }
    $this->info = $info;
  }

  /**
   * Sets the libraries associated with the extension.
   *
   * @param \Drupal\neo_build\NeoLibrary[] $libraries
   *   The libraries to associate with the extension.
   *
   * @return $this
   */
  public function setLibraries(array $libraries): self {
    $this->libraries = $libraries;
    return $this;
  }

  /**
   * Set a library associated with the extension.
   *
   * @param string $libraryName
   *   The name of the library to set.
   * @param \Drupal\neo_build\NeoLibrary $library
   *   The library to set.
   */
  public function setLibrary(string $libraryName, NeoLibrary $library): self {
    $this->libraries[$libraryName] = $library;
    return $this;
  }

  /**
   * Returns the type of the extension.
   *
   * @return string
   *   The extension type. This is usually 'module' or 'theme'.
   */
  public function getType(): string {
    return $this->extension->getType();
  }

  /**
   * Returns the internal name of the extension.
   *
   * @return string
   *   The machine name of the extension.
   */
  public function getName(): string {
    return $this->extension->getName();
  }

  /**
   * Returns the label of the extension.
   *
   * @return string
   *   The human-readable name of the extension.
   */
  public function getLabel(): string {
    return $this->extension->info['name'] ?? 'Unnamed';
  }

  /**
   * Returns the version of the extension.
   *
   * @return string
   *   The version of the extension.
   */
  public function getVersion(): string {
    return $this->info['version'] ?? '0.0.0';
  }

  /**
   * Returns the path of the extension.
   *
   * @return string
   *   The file system path of the extension.
   */
  public function getPath(): string {
    return $this->extension->getPath();
  }

  /**
   * Returns the scope of the extension.
   *
   * @return array
   *   An array of scopes the extension is available in.
   */
  public function getScope(): array {
    return $this->info['neo']['scope'] ?? [];
  }

  /**
   * Sets the weight of the extension.
   *
   * @param int $weight
   *   The weight to set.
   */
  public function setWeight(int $weight): void {
    $this->weight = $weight;
  }

  /**
   * Returns the weight of the extension.
   *
   * @return int
   *   The weight of the extension.
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * Returns the libraries associated with the extension.
   *
   * @return \Drupal\neo_build\NeoLibrary[]
   *   The libraries associated with the extension.
   */
  public function getLibraries(): array {
    return $this->libraries;
  }

  /**
   * Returns the Neo-specific information for the extension.
   *
   * @return array
   *   An array of Neo-specific information.
   */
  public function getNeoInfo(): array {
    return $this->info['neo'] ?? [];
  }

  /**
   * The Tailwind sections an extension's `neo:` block may declare.
   *
   * The closed vocabulary, in the order the preparer dispatches them. Each one
   * reaches a collection method by name.
   */
  public const TAILWIND_SECTIONS = ['theme', 'components', 'utilities', 'variants'];

  /**
   * The Tailwind sections that were withdrawn and now reach nothing.
   *
   * `base` had two routes into the build — `addTailwindBase()`, and this key.
   * The first was removed with the base layer itself; this one survived it and
   * would otherwise be dropped without a word. The extension states that the
   * key is retired; the preparer decides what to say about it.
   */
  public const RETIRED_TAILWIND_SECTIONS = ['base'];

  /**
   * Returns the Tailwind CSS configuration for the extension.
   *
   * @return array
   *   The four accepted sections, every one of them present:
   *   - theme: Custom theme configurations.
   *   - components: Component styles to include.
   *   - utilities: Utility styles to include.
   *   - variants: Variants to include.
   *   Each key maps to an array of configurations. If a key is not defined
   *   in the extension's info, it defaults to an empty array. A retired
   *   section is not among them; see getRetiredTailwindSections().
   */
  public function getTailwindInfo(): array {
    $defaults = array_fill_keys(self::TAILWIND_SECTIONS, []);
    return array_intersect_key($this->info['neo'] ?? [], $defaults) + $defaults;
  }

  /**
   * Returns the retired Tailwind sections this extension actually declares.
   *
   * Normally empty. Unknown keys are not reported here — that would need a
   * validated vocabulary for the whole `neo:` block, and without one it would
   * fire on every typo and on `group:`, which `neo_base` declares on every
   * site.
   *
   * @return array
   *   The declared retired section names, in vocabulary order.
   */
  public function getRetiredTailwindSections(): array {
    return array_values(array_intersect(
      self::RETIRED_TAILWIND_SECTIONS,
      array_keys($this->info['neo'] ?? []),
    ));
  }

  /**
   * Checks if the extension has a specific library.
   *
   * @param string $libraryName
   *   The name of the library to check for.
   *
   * @return bool
   *   TRUE if the library exists, FALSE otherwise.
   */
  public function hasLibrary(string $libraryName): bool {
    return isset($this->getLibraries()[$libraryName]);
  }

  /**
   * Returns a specific library by name.
   *
   * @param string $libraryName
   *   The name of the library to retrieve.
   *
   * @return \Drupal\neo_build\NeoLibrary|null
   *   The requested library, or NULL if it doesn't exist.
   */
  public function getLibrary(string $libraryName): ?NeoLibrary {
    return $this->getLibraries()[$libraryName] ?? NULL;
  }

  /**
   * Checks if the extension is allowed in the given scope.
   *
   * @param array $scope
   *   An array of scopes to check against.
   *
   * @return bool
   *   TRUE if the extension is allowed in the given scope, FALSE otherwise.
   */
  public function allowScope(array $scope): bool {
    return !empty(array_intersect($this->getScope(), $scope));
  }

}
