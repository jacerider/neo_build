<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;

/**
 * Rewrites libraries to work with vite.
 */
class NeoBuild {

  /**
   * The prefix every Neo build state key carries.
   */
  public const STATE_PREFIX = 'neo.build.';

  /**
   * The state key carrying the DEV flag.
   *
   * The one spelling of this key. Preparer reads it from here rather than
   * declaring its own, so the pair cannot drift apart.
   */
  public const DEV_STATE_KEY = self::STATE_PREFIX . 'dev';

  /**
   * The state key under which the prepared scope is recorded.
   */
  public const SCOPE_STATE_KEY = self::STATE_PREFIX . 'scope';

  /**
   * Whether this service is suspending the render-time library rewrite.
   *
   * An instance flag rather than a static one: prepare toggles it through the
   * injected service, and a process-global would make the render path
   * untestable and order-dependent.
   */
  protected bool $preventAlter = FALSE;

  /**
   * Constructs the Vite service object.
   */
  public function __construct(
    private readonly NeoExtensionList $neoExtensionList,
    private readonly StateInterface $state,
    private readonly ManifestResolver $manifestResolver,
    private readonly DevServer $devServer,
  ) {
  }

  /**
   * Process libraries declared to use vite.
   */
  public function processLibraries(array &$libraries, string $extension): void {
    if ($this->preventAlter === TRUE) {
      return;
    }

    if ($extension === 'core' && $this->isDevMode()) {
      // When in DEV mode, we need to override the add_js method in core ajax.
      // This is a less than ideal workaround for Vite loading CSS as JS.
      // @see https://www.drupal.org/project/drupal/issues/3334704
      $libraries['drupal.ajax']['js']['/' . $this->neoExtensionList->getNeoBuildPath() . '/src/js/ajax-neo.js'] = [];
    }

    foreach ($libraries as $libraryId => $library) {
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
   * Suspends or resumes the render-time library rewrite.
   *
   * @param bool $lock
   *   TRUE to suspend the rewrite, FALSE to resume it.
   */
  public function preventAlter(bool $lock = TRUE): void {
    $this->preventAlter = $lock === TRUE;
  }

  /**
   * Whether the render-time library rewrite is currently suspended.
   */
  public function isAlterPrevented(): bool {
    return $this->preventAlter;
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
      // No dev server URL means no dev rewrite to make. Serving the compiled
      // assets is the honest answer; pointing every asset at a URL composed
      // from an unset variable is what this plan removes. Dev mode cannot
      // normally reach this state — neo:build:dev:enable refuses without the
      // variable — so this is a guard, not a supported path.
      if ($this->devServer->getUrl() !== NULL) {
        return $this->rewriteLibraryForDev($neoLibrary);
      }
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
        'path' => $this->devServer->getUrl() . $cssPath,
        'toJs' => TRUE,
        'options' => [
          'type' => 'external',
          'attributes' => ['type' => 'module', 'neoCss' => TRUE],
        ],
      ];
    }
    if ($jsPath = $neoLibrary->getJsPath()) {
      $rewrites['js'] = [
        'path' => $this->devServer->getUrl() . $jsPath,
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
    $scopes = $neoLibrary->getScope();
    if ($cssPath = $neoLibrary->getCssPath()) {
      if ($distPath = $this->manifestResolver->resolve($cssPath, $scopes)) {
        $rewrites['css']['path'] = $distPath;
      }
    }
    if ($jsPath = $neoLibrary->getJsPath()) {
      if ($distPath = $this->manifestResolver->resolve($jsPath, $scopes)) {
        $rewrites['js']['path'] = $distPath;
        // Deliberately loaded as a classic script, unlike
        // rewriteLibraryForDev() where the Vite dev server serves real ES
        // modules.
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
    return (bool) $this->state->get(self::DEV_STATE_KEY, FALSE);
  }

  /**
   * Records whether the Neo dev server is enabled.
   *
   * @param bool $enabled
   *   TRUE when the dev server is serving this site's assets.
   */
  public function setDevMode(bool $enabled): void {
    if ($enabled) {
      $this->state->set(self::DEV_STATE_KEY, TRUE);
      return;
    }
    $this->state->delete(self::DEV_STATE_KEY);
  }

  /**
   * Get the current scope.
   *
   * This is the scope the last prepare ran for — in dev mode, the scope the dev
   * server is serving. It decides only whether a library gets dev-server URLs.
   * It is *not* the render-time active scope, which the manifest resolver
   * derives per request; the two must not be conflated.
   *
   * @return string
   *   The current scope.
   */
  public function getScope(): string {
    return (string) $this->state->get(self::SCOPE_STATE_KEY, 'front');
  }

  /**
   * Records the scope the last prepare ran for.
   *
   * @param string $scope
   *   The scope id.
   */
  public function setScope(string $scope): void {
    $this->state->set(self::SCOPE_STATE_KEY, $scope);
  }

  // The Drupal standard's deprecation format requires a removal version and a
  // drupal.org URL. This deprecation names no removal release on purpose:
  // scheduling a major is a later decision, and a version invented here to
  // satisfy a sniff would be a date nobody has agreed to. These are jacerider
  // packages with no drupal.org issue queue to reference either, so the
  // cross-reference names the replacement API instead. The tag itself is what
  // matters: it is what phpstan-deprecation-rules reads to find the callers
  // that remain.
  // phpcs:disable Drupal.Commenting.Deprecated -- No removal release is named.

  /**
   * Set vite state.
   *
   * @deprecated Use the injected neo_build service instead: setDevMode() or
   *   setScope(), or the state service directly with NeoBuild::DEV_STATE_KEY
   *   and NeoBuild::SCOPE_STATE_KEY.
   *
   * @see \Drupal\neo_build\NeoBuild::setDevMode()
   *
   * There is deliberately no runtime deprecation notice. This package family
   * has never carried one; the notice would reach roughly thirty sites for a
   * call they cannot act on themselves, and phpstan-deprecation-rules already
   * reports the remaining internal callers statically.
   */
  public static function setNeoState(string $key, mixed $value): mixed {
    if (empty($value)) {
      return \Drupal::state()->delete(self::STATE_PREFIX . $key);
    }
    return \Drupal::state()->set(self::STATE_PREFIX . $key, $value);
  }

  /**
   * Returns vite state.
   *
   * @deprecated Use the injected neo_build service instead: isDevMode() or
   *   getScope().
   *
   * @see \Drupal\neo_build\NeoBuild::setNeoState()
   */
  public static function getNeoState(string $setting, $default = NULL): mixed {
    return \Drupal::state()->get(self::STATE_PREFIX . $setting, $default);
  }

  /**
   * Returns vite state.
   *
   * @deprecated Use the injected neo_build service instead: setDevMode(FALSE).
   *
   * @see \Drupal\neo_build\NeoBuild::setNeoState()
   */
  public static function unsetNeoState(string $setting): mixed {
    return \Drupal::state()->delete(self::STATE_PREFIX . $setting);
  }

  // phpcs:enable Drupal.Commenting.Deprecated -- Back to the standard rules.

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
    // Under PHPUnit there is no server name; default rather than fail.
    $settings['host'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $settings['https'] = TRUE;
    // Global settings.
    if (isset($settings[$setting])) {
      $value = $settings[$setting];
    }
    return $value;
  }

}
