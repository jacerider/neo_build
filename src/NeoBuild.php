<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Site\Settings;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Rewrites libraries to work with vite.
 */
class NeoBuild {

  /**
   * The Vite manifest.
   *
   * @var \Drupal\neo_build\NeoManifest
   *   The Vite manifest.
   */
  private NeoManifest $manifest;

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
    private readonly ThemeManagerInterface $themeManager,
    private readonly NeoExtensionList $neoExtensionList,
  ) {
  }

  /**
   * Process libraries declared to use vite.
   */
  public function processLibraries(array &$libraries, string $extension): void {
    $theme = $this->themeManager->getActiveTheme();
    $distPath = $theme->getPath() . '/dist';
    $this->manifest = new NeoManifest($distPath . '/manifest.json', $distPath);
    if (static::$preventAlter === TRUE) {
      return;
    }

    if ($extension === 'core' && $this->isDevMode()) {
      // When in DEV mode, we need to override the add_js method in core ajax.
      // This is a less than ideal workaround for Vite loading CSS as JS.
      // @see https://www.drupal.org/project/drupal/issues/3334704
      $libraries['drupal.ajax']['js']['/' . $this->neoExtensionList->getNeoBuildPath() . '/src/js/ajax-neo.js'] = [];
    }

    foreach ($libraries as $libraryId => $library) {
      if ($libraryId === 'vite-dev-client') {
        $libraries[$libraryId]['js'] = [
          $this->getViteDevServerUrl() . '@vite/client' => $libraries[$libraryId]['js']['@vite/client'],
        ];
        continue;
      }
      if ($neoLibrary = $this->neoExtensionList->getLibrary($extension, $libraryId, $library)) {
        if ($neoLibrary->isImport()) {
          // Import libraries are automatically included in the main tailwind
          // CSS file. They are not actual libraries.
          unset($libraries[$libraryId]);
          continue;
        }

        // Fetch rewrite data.
        [
          'css' => $css,
          'js' => $js,
        ] = $this->rewriteLibrary($neoLibrary);

        if ($css) {
          foreach ($library['css'] as $type => $paths) {
            foreach ($paths as $path => $options) {
              if (!empty($css['toJs'])) {
                $libraries[$libraryId]['js'][$css['path']] = NestedArray::mergeDeep($css['options'] ?? [], $options);
              }
              else {
                $libraries[$libraryId]['css'][$type][$css['path']] = NestedArray::mergeDeep($css['options'] ?? [], $options);
              }
              unset($libraries[$libraryId]['css'][$type][$path]);
            }
          }
        }
        if ($js) {
          foreach ($library['js'] as $path => $options) {
            $libraries[$libraryId]['js'][$js['path']] = NestedArray::mergeDeep($js['options'] ?? [], $options);
            unset($libraries[$libraryId]['js'][$path]);
          }
        }
      }
    }
  }

  /**
   * Lock asset rendering in live mode.
   */
  public static function preventAlter($lock = TRUE) {
    self::$preventAlter = $lock === TRUE;
  }

  /**
   * Rewrites a library for the current environment.
   *
   * @param \Drupal\neo_build\NeoLibrary $neoLibrary
   *   The library to rewrite.
   *
   * @return array
   *   The rewritten library.
   */
  public function rewriteLibrary(NeoLibrary $neoLibrary): array {
    if ($this->isDevMode() && in_array($this->getScope(), $neoLibrary->getScope())) {
      return $this->rewriteLibraryForDev($neoLibrary);
    }
    return $this->rewriteLibraryForProd($neoLibrary);
  }

  /**
   * Rewrites a library for the development environment.
   *
   * @param \Drupal\neo_build\NeoLibrary $neoLibrary
   *   The library to rewrite.
   *
   * @return array
   *   The rewritten library.
   */
  public function rewriteLibraryForDev(NeoLibrary $neoLibrary): array {
    $rewrites = [
      'css' => NULL,
      'js' => NULL,
    ];
    if ($cssPath = $neoLibrary->getCssPath()) {
      $rewrites['css'] = [
        'path' => $this->getViteDevServerUrl() . $cssPath,
        'toJs' => TRUE,
        'options' => [
          'type' => 'external',
          'attributes' => ['type' => 'module', 'neoCss' => TRUE],
        ],
      ];
    }
    if ($jsPath = $neoLibrary->getJsPath()) {
      $rewrites['js'] = [
        'path' => $this->getViteDevServerUrl() . $jsPath,
        'options' => [
          'type' => 'external',
          'attributes' => ['type' => 'module'],
        ],
      ];
    }
    return $rewrites;
  }

  /**
   * Rewrites a library for the production environment.
   *
   * @param \Drupal\neo_build\NeoLibrary $neoLibrary
   *   The library to rewrite.
   *
   * @return array
   *   The rewritten library.
   */
  public function rewriteLibraryForProd(NeoLibrary $neoLibrary): array {
    $rewrites = [
      'css' => NULL,
      'js' => NULL,
    ];
    if ($cssPath = $neoLibrary->getCssPath()) {
      if ($distPath = $this->manifest->getDistPath($cssPath)) {
        $rewrites['css']['path'] = $distPath;
      }
    }
    if ($jsPath = $neoLibrary->getJsPath()) {
      if ($distPath = $this->manifest->getDistPath($jsPath)) {
        $rewrites['js']['path'] = $distPath;
        // Deliberately loaded as a classic script, unlike rewriteLibraryForDev()
        // where the Vite dev server serves real ES modules.
        //
        // These chunks were served as `type="module"` to keep their minified
        // top-level names from leaking to globals and colliding. But a module
        // script is deferred: it runs after the document parses, whatever
        // position Drupal gave it. That voids the ordering the whole library
        // dependency system rests on — `dependencies: [core/jquery]` still put
        // jQuery on the page but no longer guaranteed it ran first — and on the
        // AJAX/BigPipe path, where scripts are injected at runtime rather than
        // parsed, load order decayed into network timing and failed at random.
        //
        // The chunks are scoped at build time now, so there is nothing to
        // contain here.
        //
        // @see \Drupal\neo_build\NeoBuild::rewriteLibraryForDev()
        // @see neo_build/tools/neo-vite-plugin.ts (neoVitePost renderChunk)
      }
    }
    return $rewrites;
  }

  /**
   * Determines if neo dev server is enabled.
   */
  public function isDevMode(): bool {
    return self::getNeoState('dev', FALSE);
  }

  /**
   * Get the current scope.
   *
   * @return string
   *   The current scope.
   */
  public function getScope(): string {
    return self::getNeoState('scope', 'front');
  }

  /**
   * Returns Vite dev server URL.
   */
  public static function getViteDevServerUrl(): string {
    return $_ENV['DDEV_PRIMARY_URL_WITHOUT_PORT'] . ':5173/';
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
      'port' => 5173,
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
