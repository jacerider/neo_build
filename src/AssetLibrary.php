<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\neo_build\Exception\ManifestCouldNotBeLoadedException;
use Drupal\neo_build\Exception\ManifestNotFoundException;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Drupal library vite config.
 */
class AssetLibrary {

  use StringTranslationTrait;

  /**
   * Drupal asset library id.
   *
   * @var string
   */
  private $libraryId;

  /**
   * Drupal asset library.
   *
   * @var array
   */
  private $library;

  /**
   * Drupal asset library extension (module/theme) id.
   *
   * @var string
   */
  private $extension;

  /**
   * Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  private $messenger;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private $logger;

  /**
   * Theme extension list.
   *
   * @var \Drupal\Core\Extension\ThemeExtensionList
   */
  private $themes;

  /**
   * Module extension list.
   *
   * @var \Drupal\Core\Extension\ModuleExtensionList
   */
  private $modules;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  private $httpClient;

  /**
   * Scope manager.
   *
   * @var \Drupal\neo_build\ScopePluginManager
   */
  private $scopeManager;

  /**
   * Drupal app root.
   *
   * @var string
   */
  private $appRoot;

  /**
   * Constructs AssetLibrary object.
   */
  public function __construct(
    string $libraryId,
    array $library,
    string $extension,
    MessengerInterface $messenger,
    LoggerInterface $logger,
    ThemeExtensionList $themes,
    ModuleExtensionList $modules,
    ClientInterface $http_client,
    TranslationInterface $string_translation,
    ScopePluginManager $scope_manager,
    string $app_root,
  ) {
    $this->libraryId = $libraryId;
    $this->extension = $extension;
    $this->messenger = $messenger;
    $this->logger = $logger;
    $this->themes = $themes;
    $this->modules = $modules;
    $this->httpClient = $http_client;
    $this->stringTranslation = $string_translation;
    $this->scopeManager = $scope_manager;
    $this->appRoot = $app_root;
    if (isset($library['neo'])) {
      if (!is_array($library['neo'])) {
        $library['neo'] = [
          'enabled' => !empty($library['neo']),
        ];
      }
      $library['neo'] += [
        'enabled' => TRUE,
        'scope' => $this->getNeoState('scope'),
      ];
      // Use extention scope.
      if ($info = $this->getExtensionInfo()) {
        $library['neo']['scope'] = $info['neo']['scope'] ?? $library['neo']['scope'];
      }
      if (!is_array($library['neo']['scope'])) {
        $library['neo']['scope'] = [$library['neo']['scope']];
      }
    }
    $this->library = $library;
  }

  /**
   * Returns drupal asset library definition.
   */
  public function getDefinition(): array {
    return $this->library;
  }

  /**
   * Returns vite manifest.
   */
  public function getViteManifest(): ?Manifest {
    $manifestPaths = $this->getManifestPaths();
    $baseUrl = $this->getBaseUrl();
    $docroot = $this->getNeoSetting('docroot');

    try {
      $scaffold = $this->scaffoldManifest();
      return new Manifest($manifestPaths, $baseUrl, $docroot, $scaffold);
    }
    catch (ManifestNotFoundException | ManifestCouldNotBeLoadedException $e) {
      $args = [
        '@extension' => $this->extension,
        '@library' => $this->libraryId,
        '@path_to_manifest' => implode('|', $this->getManifestRelativePaths()),
      ];
      $this->messenger->addError($this->t("Could not load vite manifest for the library `@extension/@library` (@path_to_manifest). Perhaps you forgot to build frontend assets, try running `vite build` in the `@extension` theme/module. Also ensure that vite is configured to output manifest to `dist/manifest.json` in the theme/module main directory or that a different path is correctly set in `@extension.libraries.yml` in `@library.vite.manifest`.", $args));
      $this->logger->error("Could not load vite manifest for the library `@extension/@library` (@path_to_manifest). Perhaps you forgot to build frontend assets, try running `vite build` in the `@extension` theme/module. Also ensure that vite is configured to output manifest to `dist/manifest.json` in the theme/module main directory or that a different path is correctly set in `@extension.libraries.yml` in `@library.vite.manifest`.", $args);
    }
    return NULL;
  }

  /**
   * Scaffold manifest for the library if it is not in an enabled group.
   *
   * @return array
   *   The manifest scaffold.
   */
  private function scaffoldManifest(): array {
    $manifestScaffold = [];
    $baseUrl = $this->getBaseUrl();
    $docroot = $this->getNeoSetting('docroot');
    $library = $this->library;
    if (isset($library['css'])) {
      foreach ($library['css'] as $paths) {
        foreach ($paths as $path => $options) {
          $file = $baseUrl . $path;
          $file = str_replace('src', 'dist', $file);
          $file = str_replace('.scss', '.css', $file);
          $src = $docroot . $baseUrl . $path;
          $manifestScaffold[$src] = [
            'file' => $file,
            'isEntry' => TRUE,
            'src' => $src,
          ];
        }
      }
    }
    if (isset($library['js'])) {
      foreach ($library['js'] as $path => $options) {
        $file = $baseUrl . $path;
        $file = str_replace('src', 'dist', $file);
        $file = str_replace('.ts', '.js', $file);
        $src = $docroot . $baseUrl . $path;
        $manifestScaffold[$src] = [
          'file' => $file,
          'isEntry' => TRUE,
          'src' => $src,
        ];
      }
    }
    // }
    return $manifestScaffold;
  }

  /**
   * Returns base url used in rewriting library for dist.
   */
  private function getBaseUrl(): string {
    $baseUrl = $this->getNeoSetting('baseUrl');
    if (empty($baseUrl) || !is_string($baseUrl)) {
      $baseUrl = $this->getNeoSettingFromLibraryConfig('baseUrl');
    }
    if (empty($baseUrl) || !is_string($baseUrl)) {
      $baseUrl = $this->getExtensionBasePath() . '/';
    }
    return $baseUrl;
  }

  /**
   * Get manifest paths for all scopes.
   *
   * @return array
   *   The manifest paths.
   */
  private function getManifestPaths(): array {
    $paths = [];
    foreach ($this->getManifestRelativePaths() as $id => $path) {
      $paths[$id] = $this->appRoot . '/' . $path;
    }
    return $paths;
  }

  /**
   * Returns relative vite manifest path.
   */
  private function getManifestRelativePaths(): array {
    $paths = [];
    foreach ($this->scopeManager->getDefinitions() as $id => $scope) {
      $manifestPath = $this->getNeoSetting('manifest');
      if (empty($manifestPath) || !is_string($manifestPath)) {
        $manifestPath = $this->getNeoSettingFromLibraryConfig('manifest');
      }
      if (empty($manifestPath) || !is_string($manifestPath)) {
        $manifestPath = 'manifest.json';
      }
      $paths[$id] = str_replace('.json', '.' . $id . '.json', $manifestPath);
    }
    return $paths;
  }

  /**
   * Returns library extension info.
   */
  public function getExtensionInfo(): ?array {
    if ($this->themes->exists($this->extension)) {
      return $this->themes->getExtensionInfo($this->extension);
    }
    elseif ($this->modules->exists($this->extension)) {
      return $this->modules->getExtensionInfo($this->extension);
    }
    return NULL;
  }

  /**
   * Returns library extension (module/theme) base path.
   */
  public function getExtensionBasePath(): string {
    if ($this->themes->exists($this->extension)) {
      return $this->themes->getPath($this->extension);
    }
    elseif ($this->modules->exists($this->extension)) {
      return $this->modules->getPath($this->extension);
    }
    throw new \Exception('Could not find library extension (module/theme) base path.');
  }

  /**
   * Checks if library should be managed by neo.
   */
  public function shouldBeManagedByNeo(): bool {
    $enabledInSettings = $this->getNeoSetting('enabled');
    if (!is_bool($enabledInSettings)) {
      $enabledInSettings = FALSE;
    }
    $enabledInLibraryDefinition = !empty($this->library['neo']['enabled']);
    return $enabledInSettings || $enabledInLibraryDefinition;
  }

  /**
   * Determines if neo dev server or dist build should serve library assets.
   */
  public function isDevMode(): bool {
    if (!in_array($this->getNeoState('scope'), $this->getNeoSettingFromLibraryConfig('scope', ['front']))) {
      return FALSE;
    }
    if ($this->getNeoState('group') !== $this->getNeoSettingFromLibraryConfig('group', $this->getNeoState('group'))) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Returns base url of vite dev server for the library.
   */
  public function getDevServerBaseUrl(): string {
    $baseUrl = $this->getNeoSetting('devServerUrl');
    if (!is_string($baseUrl) || !UrlHelper::isValid($baseUrl)) {
      $baseUrl = $this->getNeoSettingFromLibraryConfig('devServerUrl');
    }
    if (!is_string($baseUrl) || !UrlHelper::isValid($baseUrl)) {
      $baseUrl = Build::getViteDevServerUrl();
    }
    return $baseUrl;
  }

  /**
   * Returns url of vite dev server for the library.
   */
  public function getDevServerUrl(): string {
    return $this->getDevServerBaseUrl() . '/' . $this->getExtensionBasePath();
  }

  /**
   * Returns vite state.
   */
  private function getNeoState(string $setting, mixed $default = NULL): mixed {
    return Build::getNeoState($setting, $default);
  }

  /**
   * Returns vite setting for the library or NULL.
   */
  private function getNeoSetting(string $setting): mixed {
    $value = Build::getNeoSetting($setting);

    // Extension specific settings.
    $overrides = Build::getNeoSetting('overrides');
    if (isset($overrides[$this->extension][$setting])) {
      $value = $overrides[$this->extension][$setting];
    }

    // Library specific settings.
    if (isset($overrides[$this->extension . '/' . $this->libraryId][$setting])) {
      $value = $overrides[$this->extension . '/' . $this->libraryId][$setting];
    }

    return $value;
  }

  /**
   * Returns vite library config for the library or NULL.
   */
  private function getNeoSettingFromLibraryConfig(string $setting, $default = NULL): mixed {
    if (!isset($this->library['neo']) || !is_array($this->library['neo'])) {
      return $default;
    }
    $value = $default;
    if (!empty($this->library['neo'][$setting])) {
      $value = $this->library['neo'][$setting];
    }
    return $value;
  }

}
