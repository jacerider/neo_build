<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Asset\LibraryDiscoveryParser;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Extension\ThemeExtensionList;

/**
 * Manages a list of extensions with Neo Build support.
 */
final class NeoExtensionList {

  /**
   * The list of NeoExtensions, keyed by extension name.
   *
   * @var \Drupal\neo_build\NeoExtension[]
   */
  private array $neoExtensions = [];

  /**
   * The list of built NeoExtensions, keyed by extension name.
   *
   * @var \Drupal\neo_build\NeoExtension[]
   */
  private array $neoExtensionsBuilt;

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
    if (!isset($this->neoExtensionsBuilt)) {
      $neoExtensions = [];

      $modules = array_filter($this->moduleExtensionList->getList(), function ($extension) {
        /** @var \Drupal\Core\Extension\Extension $extension */
        return $this->moduleHandler->moduleExists($extension->getName());
      });

      $themes = array_filter($this->themeExtensionList->getList(), function ($extension) {
        /** @var \Drupal\Core\Extension\Extension $extension */
        return $extension->status;
      });

      $extensions = array_merge($modules, $themes);
      foreach ($extensions as $name => $extension) {
        if ($neoExtension = $extensionsToAdd[$name] ?? $this->loadExtension($name, $extension)) {
          if (!empty($extension->info['neo'])) {
            $neoExtensions[$name] = $neoExtension;
          }
          $libraries = $this->libraryDiscoveryParser->buildByExtension($name);
          foreach ($libraries as $libraryName => $library) {
            if ($neoLibrary = $this->getLibrary($name, $libraryName, $library)) {
              $neoExtension->setLibrary($libraryName, $neoLibrary);
              $neoExtensions[$name] = $neoExtension;
            }
          }
        }
      }
      uasort($neoExtensions, function ($a, $b) {
        return $a->getWeight() <=> $b->getWeight();
      });
      $this->neoExtensionsBuilt = $neoExtensions;
    }

    if ($scope) {
      return array_filter($this->neoExtensionsBuilt, fn($neoExtension) => $neoExtension->allowScope($scope));
    }

    return $neoExtensions;
  }

  /**
   * Gets or creates a NeoLibrary for a given library definition.
   *
   * @param string $extension
   *   The extension the library belongs to.
   * @param string $libraryName
   *   The name of the library.
   * @param array $library
   *   The library definition.
   *
   * @return \Drupal\neo_build\NeoLibrary|null
   *   The NeoLibrary object, or NULL if the library is not Neo-enabled.
   */
  public function getLibrary(string $extension, string $libraryName, array $library) {
    if (!empty($library['neo'])) {
      if ($neoExtension = $this->loadExtension($extension)) {
        $library = $this->processLibrary($neoExtension->getPath(), $library);
        $neoLibrary = new NeoLibrary($neoExtension, $libraryName, $library);
        $neoExtension->setLibrary($libraryName, $neoLibrary);
        return $neoLibrary;
      }
    }
  }

  /**
   * Caches libraries for a given extension.
   *
   * The libraries from hook_library_info_alter are in the nested format
   * (css[category][path] = options, js[path] = options) because the alter
   * fires before buildByExtension() flattens them. We normalize here so
   * NeoLibrary receives the same flat format it gets from buildByExtension().
   *
   * buildByExtension() can also hand back a library this module has already
   * rewritten — anything calling all() outside the build command reads the
   * post-alter definitions. Under dev that rewrite moves the stylesheet into
   * the js array as a Vite module (flagged neoCss), which would otherwise read
   * as a second JS entry point and trip NeoLibrary's one-JS guard.
   *
   * @param string $extensionPath
   *   The path to the owning extension.
   * @param array $library
   *   The library information to process.
   *
   * @return array
   *   The processed library information.
   */
  protected function processLibrary(string $extensionPath, array $library): array {
    // Normalize CSS from nested css[category][path] to flat css[]['data'].
    if (!empty($library['css']) && !is_int(array_key_first($library['css']))) {
      $flat = [];
      foreach ($library['css'] as $files) {
        if (!is_array($files)) {
          continue;
        }
        foreach ($files as $source => $options) {
          $options = is_array($options) ? $options : [];
          $options['data'] = $options['data'] = $this->resolveSourcePath($source, $extensionPath);
          $flat[] = $options;
        }
      }
      $library['css'] = $flat;
    }
    // Normalize JS from js[path] to flat js[]['data'].
    if (!empty($library['js']) && !is_int(array_key_first($library['js']))) {
      $flat = [];
      foreach ($library['js'] as $source => $options) {
        $options = is_array($options) ? $options : [];
        $options['data'] = $options['data'] = $this->resolveSourcePath($source, $extensionPath);
        $flat[] = $options;
      }
      $library['js'] = $flat;
    }
    // Drop stylesheets the dev rewrite parked in the js array. They are a
    // delivery artifact, not an entry point.
    if (!empty($library['js'])) {
      $library['js'] = array_filter($library['js'], function ($options) {
        return empty($options['attributes']['neoCss']);
      });
    }
    return $library;
  }

  /**
   * Resolves a source path the same way buildByExtension() does.
   *
   * @param string $source
   *   The source path from the library definition.
   * @param string $extensionPath
   *   The path to the owning extension.
   *
   * @return string
   *   The resolved path.
   */
  private function resolveSourcePath(string $source, string $extensionPath): string {
    // External or protocol-free URI.
    if (str_contains($source, '://') || str_starts_with($source, '//')) {
      return $source;
    }
    // Absolute path (relative to DRUPAL_ROOT).
    if ($source[0] === '/') {
      return substr($source, 1);
    }
    // Relative path — prepend extension path.
    return $extensionPath . '/' . $source;
  }

  /**
   * Loads or creates a NeoExtension for a single extension.
   *
   * This avoids triggering a full loadModules()/loadThemes() discovery,
   * which is critical when called during hook_library_info_alter.
   *
   * @param string $name
   *   The extension machine name.
   * @param \Drupal\Core\Extension\Extension|null $extension
   *   An optional Extension object, if already available. If not provided, it
   *   will be loaded if the extension exists.
   *
   * @return \Drupal\neo_build\NeoExtension|null
   *   The NeoExtension object, or NULL if the extension cannot be found.
   */
  private function loadExtension(string $name, ?Extension $extension = NULL): ?NeoExtension {
    if (isset($this->neoExtensions[$name])) {
      return $this->neoExtensions[$name];
    }

    $this->neoExtensions[$name] = NULL;
    if (!$extension) {
      if ($this->moduleHandler->moduleExists($name)) {
        $extension = $this->moduleExtensionList->get($name);
      }
      elseif ($this->themeExtensionList->get($name)) {
        $extension = $this->themeExtensionList->get($name);
      }
    }
    if (!$extension) {
      return NULL;
    }

    $neoExtension = new NeoExtension($extension);
    $this->neoExtensions[$name] = $neoExtension;
    if ($neoExtension->getType() === 'theme') {
      $neoExtension->setWeight(count($extension->requires));
    }

    return $neoExtension;
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
