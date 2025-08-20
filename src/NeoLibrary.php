<?php

namespace Drupal\neo_build;

/**
 * Defines an library object.
 *
 * This class does not implement the Serializable interface since problems
 * occurred when using the serialize method.
 *
 * @see https://bugs.php.net/bug.php?id=66052
 */
class NeoLibrary {

  /**
   * The wrapped extension.
   *
   * @var \Drupal\neo_build\NeoExtension
   */
  private NeoExtension $extension;

  /**
   * The library name.
   *
   * @var string
   */
  private string $name;

  /**
   * The library information.
   *
   * @var array
   */
  private array $library;

  /**
   * Constructs a new NeoLibrary object.
   *
   * @param \Drupal\neo_build\NeoExtension $extension
   *   The extension the library belongs to.
   * @param string $name
   *   The library name.
   * @param array $library
   *   The library information.
   */
  public function __construct(NeoExtension $extension, string $name, array $library) {
    $this->extension = $extension;
    $this->name = $name;
    if (!empty($library['css'])) {
      if (count($library['css']) > 1) {
        throw new \InvalidArgumentException(sprintf('Library "%s" can only have one CSS file.', $name));
      }
    }
    if (!empty($library['js'])) {
      if (count($library['js']) > 1) {
        throw new \InvalidArgumentException(sprintf('Library "%s" can only have one JS file.', $name));
      }
    }
    // Make sure we have Neo config.
    if (!isset($library['neo'])) {
      $library['neo'] = [];
    }
    // Ensure neo setting is an array.
    if (!is_array($library['neo'])) {
      $library['neo'] = [];
    }
    // Always set scope.
    if (!isset($library['neo']['scope'])) {
      $library['neo']['scope'] = $this->extension->getScope();
    }
    // Make sure scope is array.
    if (!is_array($library['neo']['scope'])) {
      $library['neo']['scope'] = [$library['neo']['scope']];
    }
    $this->library = $library;
  }

  /**
   * Returns the unique identifier for the library.
   *
   * The identifier is a combination of the extension name and the library name.
   *
   * @return string
   *   The unique identifier for the library.
   */
  public function id() {
    return $this->extension->getName() . ':' . $this->name;
  }

  /**
   * Returns the name of the library.
   *
   * @return string
   *   The name of the library.
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * Returns the extension the library belongs to.
   *
   * @return \Drupal\neo_build\NeoExtension
   *   The extension the library belongs to.
   */
  public function getExtension(): NeoExtension {
    return $this->extension;
  }

  /**
   * Returns the library information.
   *
   * @return array
   *   The library information.
   */
  public function getLibrary(): array {
    return $this->library;
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
   * Checks if the library is an import.
   *
   * @return bool
   *   TRUE if the library is an import, FALSE otherwise.
   */
  public function isImport(): bool {
    return !empty($this->getCssPath()) && !empty($this->library['neo']['import']);
  }

  /**
   * Returns the JavaScript file for the library.
   *
   * @return array|null
   *   The JavaScript file information, or NULL if not found.
   */
  public function getJs(): ?array {
    if (!empty($this->library['js'])) {
      return reset($this->library['js']);
    }
    return NULL;
  }

  /**
   * Returns the JavaScript file path for the library.
   *
   * @return string|null
   *   The JavaScript file path, or NULL if not found.
   */
  public function getJsPath(): ?string {
    if ($js = $this->getJs()) {
      return $js['data'] ?? NULL;
    }
    return NULL;
  }

  /**
   * Returns the CSS file for the library.
   *
   * @return array|null
   *   The CSS file information, or NULL if not found.
   */
  public function getCss(): ?array {
    if (!empty($this->library['css'])) {
      return reset($this->library['css']);
    }
    return NULL;
  }

  /**
   * Returns the CSS file path for the library.
   *
   * @return string|null
   *   The CSS file path, or NULL if not found.
   */
  public function getCssPath(): ?string {
    if ($css = $this->getCss()) {
      return $css['data'] ?? NULL;
    }
    return NULL;
  }

  /**
   * Returns the scope of the library.
   *
   * @return array
   *   An array of scopes the library is available in.
   */
  public function getScope(): array {
    return $this->library['neo']['scope'] ?? [];
  }

  /**
   * Checks if the library is allowed in the given scope.
   *
   * @param array $scope
   *   An array of scopes to check against.
   *
   * @return bool
   *   TRUE if the library is allowed in the given scope, FALSE otherwise.
   */
  public function allowScope(array $scope): bool {
    return !empty(array_intersect($this->getScope(), $scope));
  }

}
