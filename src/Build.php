<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslationInterface;
use GuzzleHttp\ClientInterface;

/**
 * Rewrites libraries to work with vite.
 */
class Build {

  const DEFAULT_DOCROOT = 'web/';

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
   * String translation.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  private $stringTranslation;

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
   * Is dev mode.
   *
   * @var bool
   */
  private $isDevMode;

  /**
   * Prevent rendering assets in dev mode.
   *
   * @var bool
   */
  protected static $preventAlter = FALSE;

  /**
   * Constructs the Vite service object.
   */
  public function __construct(
    MessengerInterface $messenger,
    LoggerChannelFactoryInterface $logger_factory,
    ThemeExtensionList $themes,
    ModuleExtensionList $modules,
    ClientInterface $http_client,
    TranslationInterface $string_translation,
    ScopePluginManager $scope_manager,
    string $app_root,
    ) {
    $this->messenger = $messenger;
    $this->logger = $logger_factory->get('neo_build');
    $this->themes = $themes;
    $this->modules = $modules;
    $this->httpClient = $http_client;
    $this->stringTranslation = $string_translation;
    $this->scopeManager = $scope_manager;
    $this->appRoot = $app_root;
  }

  /**
   * Process libraries declared to use vite.
   */
  public function processLibraries(array &$libraries, string $extension): void {
    if (static::$preventAlter === TRUE) {
      return;
    }
    foreach ($libraries as $libraryId => $library) {
      $assetLibrary = new AssetLibrary(
        $libraryId,
        $library,
        $extension,
        $this->messenger,
        $this->logger,
        $this->themes,
        $this->modules,
        $this->httpClient,
        $this->stringTranslation,
        $this->scopeManager,
        $this->appRoot,
      );
      if (!$assetLibrary->shouldBeManagedByNeo()) {
        continue;
      }
      $libraries[$libraryId] = $this->rewriteLibrary($assetLibrary);
    }
  }

  /**
   * Lock asset rendering in live mode.
   */
  public static function preventAlter($lock = TRUE) {
    self::$preventAlter = $lock === TRUE;
  }

  /**
   * Determines if neo dev server is enabled.
   */
  public function isDevMode(): bool {
    if (!isset($this->isDevMode)) {
      $this->isDevMode = $this->getNeoSetting('useDevServer');
      if (!is_bool($this->isDevMode)) {
        $this->isDevMode = $this->getNeoState('dev', FALSE);
      }
      if ($this->isDevMode === TRUE) {
        if (!$this->getNeoState('build', FALSE)) {
          throw new \Exception('Vite production assets are stale. Please rebuild them by running <pre>npm start</pre>');
        }
      }
      if (!is_bool($this->isDevMode)) {
        $this->isDevMode = FALSE;
      }
    }
    return $this->isDevMode;
  }

  /**
   * Rewrite library for dev or dist.
   */
  private function rewriteLibrary(AssetLibrary $assetLibrary): array {
    if ($this->isDevMode() && $assetLibrary->isDevMode()) {
      return $this->rewriteLibraryForDev($assetLibrary);
    }
    return $this->rewriteLibraryForDist($assetLibrary);
  }

  /**
   * Rewrite library using dist output.
   */
  private function rewriteLibraryForDist(AssetLibrary $assetLibrary): array {
    $manifest = $assetLibrary->getViteManifest();
    $library = $assetLibrary->getDefinition();

    if ($manifest === NULL) {
      return $library;
    }

    if (isset($library['css'])) {
      foreach ($library['css'] as $type => $paths) {
        foreach ($paths as $path => $options) {
          $newPath = $manifest->getChunk($path, FALSE);
          if ($newPath === NULL) {
            // Don't rewrite assets not present in the manifest.
            continue;
          }
          unset($library['css'][$type][$path]);
          $library['css'][$type][$newPath] = $options;
        }
      }
    }

    if (isset($library['js'])) {
      foreach ($library['js'] as $path => $options) {
        $newPath = $manifest->getChunk($path, FALSE);
        if ($newPath === NULL) {
          // Don't rewrite assets not present in the manifest.
          continue;
        }
        unset($library['js'][$path]);

        $attributes = $options['attributes'] ?? [];
        $options['attributes'] = $attributes;
        $library['js'][$newPath] = $options;

        $styles = $manifest->getStyles($path);
        foreach ($styles as $stylePath) {
          $library['css']['component'][$stylePath] = [];
        }
      }
    }
    return $library;
  }

  /**
   * Rewrite library to use vite dev server.
   */
  private function rewriteLibraryForDev(AssetLibrary $assetLibrary): array {
    $library = $assetLibrary->getDefinition();
    $devServerBaseUrl = $assetLibrary->getDevServerUrl();
    // $devServerBaseUrl = (static::getNeoSetting('https') ? 'https' : 'http') . '://' . static::getNeoSetting('host') . '/api/neo/asset/' . $assetLibrary->getExtensionBasePath();
    if (isset($library['css'])) {
      foreach ($library['css'] as $type => $paths) {
        foreach ($paths as $path => $options) {
          if (!$this->shouldAssetBeManagedByVite($path, $options)) {
            continue;
          }
          unset($library['css'][$type][$path]);
          $options['type'] = 'external';
          $attributes = $options['attributes'] ?? [];
          $attributes['type'] = 'module';
          $options['attributes'] = $attributes;
          $newPath = $devServerBaseUrl . '/' . $path;
          $library['js'][$newPath] = $options;
        }
      }
    }

    if (isset($library['js'])) {
      foreach ($library['js'] as $path => $options) {
        if (!$this->shouldAssetBeManagedByVite($path, $options)) {
          continue;
        }
        unset($library['js'][$path]);
        $options['type'] = 'external';
        $attributes = $options['attributes'] ?? [];
        $attributes['type'] = 'module';
        $options['attributes'] = $attributes;
        $newPath = $devServerBaseUrl . '/' . $path;
        $library['js'][$newPath] = $options;
      }
    }
    return $library;
  }

  /**
   * Tries to determine if asset should be managed by vite.
   */
  private function shouldAssetBeManagedByVite(string $path, array $options): bool {
    return $path[0] !== DIRECTORY_SEPARATOR
      && strpos($path, 'http') !== 0
      && (!isset($options['type']) || $options['type'] !== 'external')
      && (!isset($options['vite']) || $options['vite'] !== FALSE)
      && (!isset($options['vite']['enabled']) || $options['vite']['enabled'] !== FALSE);
  }

  /**
   * Returns vite state.
   */
  public static function getViteDevServerUrl(): mixed {
    if (!empty($_ENV['IS_DDEV_PROJECT'])) {
      return '/neo-assets';
    }
    return (static::getNeoSetting('https') ? 'https' : 'http') . '://' . static::getNeoSetting('host') . ':' . static::getNeoSetting('port');
  }

  /**
   * Set vite state.
   */
  public static function setNeoState(string $key, mixed $value): mixed {
    if (empty($value)) {
      return \Drupal::state()->delete('neo.build.' . $key);
    }
    return \Drupal::state()->set('neo.build.' . $key, $value);
  }

  /**
   * Returns vite state.
   */
  public static function getNeoState(string $setting, $default = NULL): mixed {
    return \Drupal::state()->get('neo.build.' . $setting, $default);
  }

  /**
   * Returns vite state.
   */
  public static function unsetNeoState(string $setting): mixed {
    return \Drupal::state()->delete('neo.build.' . $setting);
  }

  /**
   * Returns vite setting for the library or NULL.
   */
  public static function getNeoSetting(string $setting, $default = NULL): mixed {
    $settings = Settings::get('neo', []);
    $value = $default;
    if (!is_array($settings)) {
      return $default;
    }
    $settings += [
      'docroot' => static::DEFAULT_DOCROOT,
      'host' => 'localhost',
      'port' => 5173,
      'https' => FALSE,
    ];
    $settings['host'] = $_SERVER['SERVER_NAME'];
    $settings['https'] = TRUE;
    // Global settings.
    if (isset($settings[$setting])) {
      $value = $settings[$setting];
    }
    return $value;
  }

}
