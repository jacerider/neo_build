<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Asset\LibraryDiscoveryParser;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Extension\ThemeExtensionList;

/**
 * Manages a list of extensions with Neo Build support.
 */
final class NeoExtensionList {

  /**
   * The loaded modules.
   *
   * @var \Drupal\neo_build\NeoExtension[]
   */
  private array $modules;

  /**
   * The loaded themes.
   *
   * @var \Drupal\neo_build\NeoExtension[]
   */
  private array $themes;

  /**
   * Loaded libraries keyed by module/theme name.
   *
   * @var array
   */
  private array $libraries;

  /**
   * Constructs a NeoExtensionList object.
   */
  public function __construct(
    private readonly ModuleHandler $moduleHandler,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly LibraryDiscoveryParser $libraryDiscoveryParser,
  ) {}

  /**
   * Returns all extensions with Neo Build support.
   *
   * @return \Drupal\neo_build\NeoExtension[]
   *   An array of NeoExtension objects representing extensions with Neo Build
   *   support.
   */
  public function all(?array $scope = NULL): array {
    $extensions = array_merge($this->modules($scope), $this->themes($scope));
    uasort($extensions, function ($a, $b) {
      return $a->getWeight() <=> $b->getWeight();
    });
    return $extensions;
  }

  /**
   * Returns all modules with Neo Build support.
   *
   * @return \Drupal\neo_build\NeoExtension[]
   *   An array of NeoExtension objects representing modules with Neo Build
   *   support.
   */
  public function modules(?array $scope = NULL): array {
    if (!isset($this->modules)) {
      $this->modules = [];
      $extensions = array_filter($this->moduleHandler->getModuleList(), function ($extension) {
        return $this->moduleHandler->moduleExists($extension->getName());
      });
      foreach ($extensions as $name => $extension) {
        $info = $this->moduleExtensionList->getExtensionInfo($name);
        $extension->info = $info;
        $neoExtension = new NeoExtension($extension);
        if (!empty($info['neo'])) {
          $neoExtension->setLibraries($this->libraries($neoExtension, $scope));
          $this->modules[$name] = $neoExtension;
          // $this->modules[$name] = new NeoExtension($extension, $this->libraries($extension, $scope));
        }
        elseif (!isset($this->modules[$name])) {
          $libraries = $this->libraries($neoExtension, $scope);
          if ($libraries) {
            $neoExtension->setLibraries($libraries);
            $this->modules[$name] = $neoExtension;
          }
        }
      }
      uasort($this->modules, function ($a, $b) {
        return $a->getWeight() <=> $b->getWeight();
      });
    }
    if ($scope) {
      return array_filter($this->modules, fn($module) => $module->allowScope($scope));
    }
    return $this->modules;
  }

  /**
   * Returns all themes with Neo Build support.
   *
   * @return \Drupal\neo_build\NeoExtension[]
   *   An array of NeoExtension objects representing themes with Neo Build
   *   support.
   */
  public function themes(?array $scope = NULL): array {
    if (!isset($this->themes)) {
      $this->themes = [];
      $extensions = array_filter($this->themeExtensionList->getList(), function ($extension) {
        /** @var \Drupal\Core\Extension\Extension $extension */
        return $extension->status;
      });
      foreach ($extensions as $name => $extension) {
        $info = $this->themeExtensionList->getExtensionInfo($name);
        $extension->info = $info;
        $neoExtension = new NeoExtension($extension);
        if (!empty($info['neo'])) {
          $neoExtension->setLibraries($this->libraries($neoExtension, $scope));
          $neoExtension->setWeight(count($extension->requires));
          $this->themes[$name] = $neoExtension;
        }
      }
      uasort($this->themes, function ($a, $b) {
        return $a->getWeight() <=> $b->getWeight();
      });
    }
    if ($scope) {
      return array_filter($this->themes, fn($theme) => $theme->allowScope($scope));
    }
    return $this->themes;
  }

  /**
   * Returns all libraries for a given extension.
   *
   * @param \Drupal\neo_build\NeoExtension $extension
   *   The extension to get libraries for.
   * @param array|null $scope
   *   An optional array of scopes to filter libraries by.
   *
   * @return \Drupal\neo_build\NeoLibrary[]
   *   An array of NeoLibrary objects representing the libraries for the
   *   extension.
   */
  protected function libraries(NeoExtension $extension, ?array $scope = NULL): array {
    $name = $extension->getName();
    if (!isset($this->libraries[$name])) {
      $this->libraries[$name] = [];
      foreach ($this->libraryDiscoveryParser->buildByExtension($name) as $libraryName => $library) {
        if (!empty($library['neo']) && !isset($this->modules[$name])) {
          $this->libraries[$name][$libraryName] = new NeoLibrary($extension, $libraryName, $library);
        }
      }
    }
    if ($scope) {
      return array_filter($this->libraries[$name], fn($library) => $library->allowScope($scope));
    }
    return $this->libraries[$name];
  }

  /**
   * Returns a specific library by name.
   *
   * @param string $extensionName
   *   The name of the extension the library belongs to.
   * @param string $libraryName
   *   The name of the library to retrieve.
   * @param array|null $scope
   *   An optional array of scopes to filter the library by.
   *
   * @return \Drupal\neo_build\NeoLibrary|null
   *   The requested library, or NULL if it doesn't exist.
   */
  public function getLibrary(string $extensionName, string $libraryName, ?array $scope = NULL): ?NeoLibrary {
    $extensions = $this->all($scope);
    foreach ($extensions as $extension) {
      if ($extension->getName() === $extensionName) {
        return $extension->getLibrary($libraryName);
      }
    }
    return NULL;
  }

  /**
   * Checks if a specific library exists.
   *
   * @param string $extensionName
   *   The name of the extension the library belongs to.
   * @param string $libraryName
   *   The name of the library to check.
   * @param array|null $scope
   *   An optional array of scopes to filter the library by.
   *
   * @return bool
   *   TRUE if the library exists, FALSE otherwise.
   */
  public function hasLibrary(string $extensionName, string $libraryName, ?array $scope = NULL): bool {
    return $this->getLibrary($extensionName, $libraryName, $scope) !== NULL;
  }

  /**
   * Returns the path to the Neo Build module.
   *
   * @return string
   *   The path to the Neo Build module.
   */
  public function getNeoBuildPath(): string {
    return $this->moduleHandler->getModule('neo_build')->getPath();
  }

}
