<?php

declare(strict_types=1);

namespace Drupal\neo_build\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\neo_build\Build;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\neo_build\Event\NeoBuildEvent;
use Drush\Commands\DrushCommands as CoreCommands;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Drush commands for Neo Build.
 */
class DrushCommands extends CoreCommands {

  use StringTranslationTrait;

  /**
   * The doc root.
   *
   * @var string
   */
  protected $docRoot;

  /**
   * The Neo config file directory uri.
   *
   * @var string
   */
  protected $neoDirectory;

  /**
   * The Cite config file name.
   *
   * @var string
   */
  protected $neoConfigFileName = 'neo.json';

  /**
   * The tailwind src suffix.
   *
   * @var string
   */
  protected $tailwindSrcSuffix = '/src/**/*.{js,ts,jsx,tsx,php}';

  /**
   * The tailwind module suffix.
   *
   * @var string
   */
  protected $tailwindModuleSuffix = '/*.{module,inc}';

  /**
   * The tailwind theme suffix.
   *
   * @var string
   */
  protected $tailwindThemeSuffix = '/*.theme';

  /**
   * The tailwind twig suffix.
   *
   * @var string
   */
  protected $tailwindTwigSuffix = '/templates/**/*.twig';

  /**
   * The stylelint suffix.
   *
   * @var string
   */
  protected $stylelintSuffix = '/**/*.{css,scss}';

  /**
   * {@inheritDoc}
   */
  public function __construct(
    private readonly string $appRoot,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly MessengerInterface $messenger,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly FileSystemInterface $fileSystem,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly TwigEnvironment $twig,
    private readonly PluginManagerInterface $scopeManager,
    private readonly PluginManagerInterface $groupManager
  ) {
    parent::__construct();
  }

  /**
   * Generate vite.config.json.
   *
   * @command neo:build
   * @usage drush neo:build
   *   Run the neoBuild build generator.
   * @aliases neo
   *
   * @throws \Exception
   *   If no index or no server were passed or passed values are invalid.
   */
  public function neoBuild(string $scope = 'front', string $group = 'custom') {
    $root = $this->getRoot();
    if (!$root) {
      $this->output()->writeln(dt('<info>[neo]</info> <error>Neo install failed. Could not find project root.</error>'));
      return;
    }
    $docRoot = './' . Build::getNeoSetting('docroot');
    $modules = array_filter($this->moduleHandler->getModuleList(), function ($extension) {
      return $this->moduleHandler->moduleExists($extension->getName());
    });

    Build::setNeoState('scope', $scope);
    Build::setNeoState('group', $group);

    $config = [
      'host' => Build::getNeoSetting('host'),
      'port' => Build::getNeoSetting('port'),
      'https' => Build::getNeoSetting('https'),
      'outDir' => './' . Build::getNeoSetting('docroot'),
      'ignored' => [
        '**/.ddev/**/*',
        '**/web/core/**/*',
        '**/web/profiles/**/*',
        '**/web/sites/**/*',
      ],
      // Record requested scope so we can use it in the tailwind config. Will
      // restore to 'front' when not in dev mode.
      'devScope' => Build::getNeoState('dev', FALSE) ? $scope : 'front',
      'devGroup' => Build::getNeoState('dev', FALSE) ? $group : 'custom',
      'ts' => [
        'include' => [
          $docRoot . $this->moduleHandler->getModule('neo_build')->getPath() . '/src/js/typings/*.d.ts',
        ],
      ],
      'tailwind' => [
        'content' => [],
        'theme' => [],
        'base' => [],
        'components' => [],
        'utilities' => [],
        'safelist' => [],
      ],
      'scopes' => [],
      'groups' => [],
    ];

    $this->libraryDiscovery->clearCachedDefinitions();
    Build::preventAlter();

    $scopes = $this->scopeManager->getDefinitions();
    foreach ($scopes as $scope => $scope_definition) {
      $this->output()->writeln(dt('<info>[neo]</info> Prepare Scope: @scope', [
        '@scope' => $scope_definition['label'],
      ]));
      $config['scopes'][$scope] = [
        'id' => $scope,
        'label' => $scope_definition['label'],
      ] + $this->neoBuildScope($modules, (string) $scope, $config);
    }

    $config['ts']['include'] = array_values($config['ts']['include']);
    $config['tailwind']['content'] = array_values($config['tailwind']['content']);
    $groups = $this->groupManager->getDefinitions();
    foreach ($config['groups'] as $groupId => $group) {
      if (!isset($groups[$groupId])) {
        unset($groups[$groupId]);
      }
      foreach ($group as &$file) {
        // Normalize file to what is expected after build.
        $file = str_replace('/src/', '/dist/', $file);
        $file = str_replace('/lib/', '/dist/lib/', $file);
        $file = str_replace('/ts/', '/js/', $file);
        $file = str_replace('.ts', '.js', $file);
        $file = str_replace('.scss', '.css', $file);
      }
      $group = array_values($group);
      $config['groups'][$groupId] = [
        'id' => $groupId,
        'label' => $groups[$groupId]['label'],
        'files' => $group,
      ];
    }
    foreach ($groups as $groupId => $group) {
      foreach ($group['include'] as $groupIncludeId) {
        if (!empty($config['groups'][$groupIncludeId]['files'])) {
          $config['groups'][$groupId]['files'] = array_merge($config['groups'][$groupId]['files'], $config['groups'][$groupIncludeId]['files']);
        }
      }
    }

    // Tailwind colors that are always present.
    $config['tailwind']['theme']['colors']['transparent'] = 'transparent';
    $config['tailwind']['theme']['colors']['current'] = 'currentColor';
    $config['tailwind']['theme']['colors']['inherit'] = 'inherit';
    $config['tailwind']['theme']['colors']['white'] = 'rgb(var(--color-white) / <alpha-value>)';
    $config['tailwind']['base'][':root']['--color-white'] = '255 255 255';
    $config['tailwind']['theme']['colors']['black'] = 'rgb(var(--color-black) / <alpha-value>)';
    $config['tailwind']['base'][':root']['--color-black'] = '0 0 0';
    $config['tailwind']['theme']['colors']['shadow']['dark'] = 'rgb(var(--color-shadow) / <alpha-value>)';
    $config['tailwind']['base'][':root']['--color-shadow'] = '0 0 0';

    $event = new NeoBuildEvent($config, $docRoot);
    $this->eventDispatcher->dispatch($event, NeoBuildEvent::EVENT_NAME);
    $config = $event->getConfig();

    Build::preventAlter(FALSE);
    $this->libraryDiscovery->clearCachedDefinitions();

    $this->fileSystem->saveData(json_encode($config['ts'], JSON_PRETTY_PRINT), $root . '/tsconfig.neo.json', FileExists::Replace);
    unset($config['ts']);
    $this->fileSystem->saveData(json_encode($config, JSON_PRETTY_PRINT), $root . '/neo.json', FileExists::Replace);

    $this->output()->writeln(dt('<info>[neo]</info> Prepare Success'));

    Cache::invalidateTags(['exo_build:build']);
  }

  /**
   * Build config scope.
   *
   * @param \Drupal\Core\Extension\Extension[] $modules
   *   The modules.
   * @param string $scope
   *   The scope.
   * @param array $globalConfig
   *   The global config.
   *
   * @return array
   *   The scope config.
   */
  protected function neoBuildScope(array $modules, string $scope, array &$globalConfig) {
    $config = [
      'vite' => [
        'lib' => [],
        'scssInclude' => [],
        'scssAdditionalData' => [],
      ],
      'tailwind' => [
        'content' => [],
        'theme' => [],
        'base' => [],
        'components' => [],
        'utilities' => [],
        'safelist' => [],
      ],
      'stylelint' => [],
    ];
    $relativeRoot = './';
    $themes = $this->getScopedThemes($scope);
    $extensions = array_merge($modules, $themes);
    foreach ($extensions as $extension) {
      $this->neoBuildExtension($extension, $scope, $config, $globalConfig);
    }
    if (empty($config['vite']['lib'])) {
      $this->output()->writeln(dt('<info>[neo]</info> [Libraries] No supported libraries were found. See readme for more information.'));
      $config['vite']['lib']['na'] = $relativeRoot . $this->moduleHandler->getModule('neo_build')->getPath() . '/install/neo/na.ts';
    }
    if (empty($config['tailwind']['content'])) {
      $this->output()->writeln(dt('<info>[neo]</info> [Tailwind] No supported libraries were found. See readme for more information.'));
      $config['tailwind']['content']['na'] = $relativeRoot . $this->moduleHandler->getModule('neo_build')->getPath() . '/install/neo/na.ts';
    }

    // Cleanup and convert to indexed arrays.
    $config['vite']['lib'] = array_values($config['vite']['lib']);
    $config['tailwind']['safelist'] = array_unique($config['tailwind']['safelist']);
    $config['tailwind']['content'] = array_values(array_diff_key($config['tailwind']['content'], $globalConfig['tailwind']['content']));
    $config['stylelint'] = array_values($config['stylelint']);
    return $config;
  }

  /**
   * Build config for a single extension.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension.
   * @param string $scope
   *   The scope.
   * @param array $scopeConfig
   *   The scope config.
   * @param array $globalConfig
   *   The global config.
   */
  protected function neoBuildExtension(Extension $extension, string $scope, array &$scopeConfig, array &$globalConfig) {
    $docRoot = './' . Build::getNeoSetting('docroot');
    $relativeRoot = './';
    $id = $extension->getName();
    /** @var \Drupal\Core\Extension\Extension $extension */
    if ($extension->getType() === 'module') {
      $info = $this->moduleExtensionList->getExtensionInfo($id);
      if (!empty($info['neo'])) {
        $globalConfig['tailwind']['content'][$id . ':Files'] = $docRoot . $extension->getPath() . $this->tailwindSrcSuffix;
        $globalConfig['tailwind']['content'][$id . ':Module'] = $docRoot . $extension->getPath() . $this->tailwindModuleSuffix;
        $globalConfig['tailwind']['content'][$id . ':Twig'] = $docRoot . $extension->getPath() . $this->tailwindTwigSuffix;
        if (is_array($info['neo'])) {
          foreach (['theme', 'base', 'components', 'utilities'] as $layer) {
            if (isset($info['neo'][$layer])) {
              $globalConfig['tailwind'][$layer] = NestedArray::mergeDeep($globalConfig['tailwind'][$layer], $info['neo'][$layer]);
            }
          }
          if (isset($info['neo']['safelist'])) {
            $globalConfig['tailwind']['safelist'] = array_merge($globalConfig['tailwind']['safelist'], $info['neo']['safelist']);
          }
        }
      }
    }
    elseif (isset($extension->info)) {
      if (!empty($extension->info['neo'])) {
        $themeScopes = $scope;
        if (is_array($extension->info['neo']) && isset($extension->info['neo']['scope'])) {
          $themeScopes = $extension->info['neo']['scope'];
        }
        if (!is_array($themeScopes)) {
          $themeScopes = [$themeScopes];
        }
        if (in_array($scope, $themeScopes)) {
          $scopeConfig['tailwind']['content'][$id . ':Files'] = $docRoot . $extension->getPath() . $this->tailwindSrcSuffix;
          $scopeConfig['tailwind']['content'][$id . ':Module'] = $docRoot . $extension->getPath() . $this->tailwindModuleSuffix;
          $scopeConfig['tailwind']['content'][$id . ':Twig'] = $docRoot . $extension->getPath() . $this->tailwindTwigSuffix;
        }
        if (is_array($extension->info['neo'])) {
          foreach (['theme', 'base', 'components', 'utilities'] as $layer) {
            if (isset($extension->info['neo'][$layer])) {
              $scopeConfig['tailwind'][$layer] = NestedArray::mergeDeep($extension->info['neo'][$layer], $scopeConfig['tailwind'][$layer]);
            }
          }
          if (isset($extension->info['neo']['safelist'])) {
            $scopeConfig['tailwind']['safelist'] = array_merge($extension->info['neo']['safelist'], $scopeConfig['tailwind']['safelist']);
          }
        }
      }
    }
    $library_file = $extension->getPath() . '/' . $id . '.libraries.yml';
    if (is_file($this->appRoot . '/' . $library_file)) {
      $libraries = $this->libraryDiscovery->getLibrariesByExtension($id);
      foreach ($libraries as $key => $library) {
        $library['key'] = $key;
        if (!empty($library['neo'])) {
          // Get scope.
          $libraryScopes = $scope;
          if (is_array($library['neo']) && isset($library['neo']['scope'])) {
            $libraryScopes = $library['neo']['scope'];
          }
          if (!is_array($libraryScopes)) {
            $libraryScopes = [$libraryScopes];
          }
          if (!in_array($scope, $libraryScopes)) {
            continue;
          }
          // Get group.
          $libraryGroup = 'custom';
          if (is_array($library['neo']) && isset($library['neo']['group'])) {
            $libraryGroup = $library['neo']['group'];
          }
          // Process.
          if (!empty($library['css']) || !empty($library['js'])) {
            $scopeConfig['tailwind']['content'][$id . ':Files'] = $docRoot . $extension->getPath() . $this->tailwindSrcSuffix;
            $scopeConfig['tailwind']['content'][$id . ':Twig'] = $docRoot . $extension->getPath() . $this->tailwindTwigSuffix;
            if ($extension->getType() === 'theme') {
              $scopeConfig['tailwind']['content'][$id . ':Theme'] = $docRoot . $extension->getPath() . $this->tailwindThemeSuffix;
            }
          }
          // CSS.
          if (!empty($library['css'])) {
            if (count($library['css']) > 1) {
              $this->messenger->addError($this->t('The library @id is trying to use Neo but specified more than 1 CSS file. This is not supported.', [
                '@id' => $id . ':' . $key,
              ]));
              continue;
            }
            $css = reset($library['css']);
            $scopeConfig['vite']['lib'][$id . ':' . $key . ':Css'] = $relativeRoot . $css['data'];
            $scopeConfig['stylelint'][$id . ':' . $key . ':Css'] = $docRoot . $css['data'];
            $globalConfig['groups'][$libraryGroup][$id . ':' . $key . ':Css'] = $css['data'];

            // Support glob CSS includes.
            if ($content = file_get_contents($relativeRoot . $css['data'])) {
              $pattern = '/^([ \t]*(?:\/\*.*)?)@(import|use)\s+["\']([^"\']+\*[^"\']*(?:\.scss|\.sass)?)["\'];?([ \t]*(?:\/[\/*].*)?)$/m';
              preg_match_all($pattern, $content, $matches, PREG_SET_ORDER, 0);
              foreach ($matches as $match) {
                if (!empty($match[3])) {
                  foreach (glob(dirname($relativeRoot . $css['data']) . '/' . $match[3]) as $importKey => $importPath) {
                    $importPath = str_replace($relativeRoot, $docRoot, $importPath);
                    $scopeConfig['stylelint'][$id . ':' . $key . ':Css' . ':' . $importKey] = $importPath;
                  }
                }
              }
            }
          }
          // JS.
          if (!empty($library['js'])) {
            if (count($library['js']) > 1) {
              $this->messenger->addError($this->t('The library @id is trying to use Neo but specified more than 1 Javascript file. This is not supported.', [
                '@id' => $id . ':' . $key,
              ]));
              continue;
            }
            $js = reset($library['js']);
            $scopeConfig['vite']['lib'][$id . ':' . $key . ':' . 'Js'] = $relativeRoot . $js['data'];
            $globalConfig['groups'][$libraryGroup][$id . ':' . $key . ':Js'] = $js['data'];
            if (substr($js['data'], -3) === '.ts') {
              $globalConfig['ts']['include'][$id . ':' . $key . ':' . 'Js'] = $docRoot . $js['data'];
              if (is_dir($extension->getPath() . '/src/js/typings')) {
                $globalConfig['ts']['include'][$id . ':' . $key . ':' . 'Typing'] = $docRoot . $extension->getPath() . '/src/js/typings/*.d.ts';
              }
            }
          }
          // Includes.
          $this->neoBuildExtensionScssInclude($extension, $library, $scopeConfig, $globalConfig);
          $this->neoBuildExtensionScssRequire($extension, $library, $scopeConfig, $globalConfig);
        }
      }
    }
  }

  /**
   * Build extension include config.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension.
   * @param array $library
   *   The library.
   * @param array $scopeConfig
   *   The scope config.
   * @param array $globalConfig
   *   The global config.
   */
  protected function neoBuildExtensionScssInclude(Extension $extension, array $library, array &$scopeConfig, array &$globalConfig) {
    if (is_array($library['neo']) && !empty($library['neo']['include'])) {
      $id = $extension->getName();
      $key = $library['key'];
      $isDev = $this->neoBuildDevEnabled();
      $docRoot = './' . Build::getNeoSetting('docroot');
      $relativeRoot = './';
      foreach ($library['neo']['include'] as $path => $data) {
        $files = [];
        $location = $extension->getPath() . '/' . $path;
        if (is_dir($location)) {
          $viteConfig['scssInclude'][] = $docRoot . $location;
          $files = array_diff(scandir($location), [
            '.',
            '..',
          ]);
        }
        else {
          if (!file_exists($location)) {
            $this->messenger->addError($this->t('The library is trying to include a scss file that does not exist.'));
            continue;
          }
          $pathinfo = pathinfo($location);
          if ($pathinfo['extension'] !== 'scss') {
            $this->messenger->addError($this->t('The library is trying to include a scss file that does not exist.'));
            continue;
          }
          $location = $pathinfo['dirname'];
          $scopeConfig['vite']['scssInclude'][] = $docRoot . $location;
          $scopeConfig['stylelint'][$id . ':' . $key . ':' . 'Include'] = $docRoot . $location . $this->stylelintSuffix;
          $files[] = $pathinfo['basename'];
        }
        if ($isDev) {
          foreach ($files as $file) {
            $pathinfo = pathinfo($location . '/' . $file);
            if ($pathinfo['extension'] === 'scss') {
              $name = $pathinfo['filename'];
              $this->output()->writeln(dt('<info>[neo]</info> Sass Include'));
              $this->output()->writeln(dt('    File: <comment>"@info"</comment>', ['@info' => $relativeRoot . $location . '/' . $file]));
              $this->output()->writeln(dt('    Use: <comment>"@info"</comment>', ['@info' => "@use '$name';"]));
            }
          }
        }
      }
    }
  }

  /**
   * Build extension require config.
   *
   * @param \Drupal\Core\Extension\Extension $extension
   *   The extension.
   * @param array $library
   *   The library.
   * @param array $scopeConfig
   *   The scope config.
   * @param array $globalConfig
   *   The global config.
   */
  protected function neoBuildExtensionScssRequire(Extension $extension, array $library, array &$scopeConfig, array &$globalConfig) {
    if (is_array($library['neo']) && !empty($library['neo']['require'])) {
      $id = $extension->getName();
      $key = $library['key'];
      $isDev = $this->neoBuildDevEnabled();
      $docRoot = './' . Build::getNeoSetting('docroot');
      foreach ($library['neo']['require'] as $path => $data) {
        $location = $extension->getPath() . '/' . $path;
        if (!file_exists($location)) {
          $this->messenger->addError($this->t('The library is trying to require a scss file that does not exist.'));
          continue;
        }
        $pathinfo = pathinfo($location);
        if ($pathinfo['extension'] !== 'scss') {
          $this->messenger->addError($this->t('The library is trying to require a scss file that does not exist.'));
          continue;
        }
        $parents = explode('/', $pathinfo['dirname']);
        $parentDirectory = $parents[count($parents) - 1];
        $directory = implode('/', $parents);
        $name = $pathinfo['filename'];
        $alias = $data['namespace'] ?? $parentDirectory . ucwords(str_replace([
          '_',
          '-',
        ], ' ', $name));
        $scopeConfig['vite']['scssInclude'][] = $docRoot . $directory;
        $scopeConfig['stylelint'][$id . ':' . $key . ':' . 'Require'] = $docRoot . $directory . $this->stylelintSuffix;
        $scopeConfig['vite']['scssAdditionalData'][] = "@use '$name' as $alias;\n";
        if ($isDev) {
          $this->output()->writeln(dt('<info>[neo]</info> Sass Require'));
          $this->output()->writeln(dt('    File: <comment>"@info"</comment>', ['@info' => $docRoot . $location]));
          $this->output()->writeln(dt('    Namespace: <comment>"@info"</comment>', ['@info' => $alias]));
        }
      }
    }
  }

  /**
   * Get the relative path betwen two directories.
   *
   * @return string
   *   The relative path.
   */
  protected function relativePath($from, $to, $ps = DIRECTORY_SEPARATOR) {
    $arFrom = explode($ps, rtrim($from, $ps));
    $arTo   = explode($ps, rtrim($to, $ps));

    while (count($arFrom) && count($arTo) && ($arFrom[0] == $arTo[0])) {
      array_shift($arFrom);
      array_shift($arTo);
    }

    return str_pad("", count($arFrom) * 3, '..' . $ps) . implode($ps, $arTo);
  }

  /**
   * Flag build as started.
   *
   * @command neo:build:build:start
   * @usage drush neo:build:build:start
   *   Flag build as started.
   * @aliases neo-build-start
   */
  public function neoBuildStart() {
    Build::setNeoState('build', TRUE);
    $this->output()->writeln(dt('<info>[neo]</info> Build has started.'));
  }

  /**
   * Flag build as ended.
   *
   * @command neo:build:build:end
   * @usage drush neo:build:build:enable
   *   Flag build as ended.
   * @aliases neo-build-end
   */
  public function neoBuildEnd() {
    Build::unsetNeoState('build');
    Build::unsetNeoState('scope');
    Build::unsetNeoState('group');
    Cache::invalidateTags(['library_info', 'theme_registry']);
    // Flush asset file caches.
    // phpcs:disable
    // @phpstan-ignore-next-line
    \Drupal::service('asset.css.collection_optimizer')->deleteAll();
    // @phpstan-ignore-next-line
    \Drupal::service('asset.js.collection_optimizer')->deleteAll();
    // @phpstan-ignore-next-line
    \Drupal::service('asset.query_string')->reset();
    // phpcs:enable
    $this->output()->writeln(dt('<info>[neo]</info> Build has ended.'));
  }

  /**
   * Get vite dev server status.
   *
   * @return bool
   *   Returns TRUE if in dev mode.
   */
  public function neoBuildDevEnabled() {
    return Build::getNeoState('dev', FALSE);
  }

  /**
   * Enable automatic tracking of vite dev server.
   *
   * @command neo:build:dev:enable
   * @usage drush neo:build:dev:enable
   *   Enable automatic tracking of vite dev server.
   * @aliases neo-dev-enable
   */
  public function neoBuildDevEnable() {
    Build::setNeoState('dev', TRUE);
    Build::setNeoState('devAsDdev', !empty($_ENV['NEO_DDEV']));
    $this->fileSystem->saveData('', $this->getRoot() . '/.git/index.lock', FileExists::Replace);
    $this->output()->writeln(dt('<info>[neo]</info> Automatic tracking of Neo DEV server enabled.'));
  }

  /**
   * Disable automatic tracking of vite dev server.
   *
   * @command neo:build:dev:disable
   * @usage drush neo:build:dev:disable
   *   Disable automatic tracking of vite dev server.
   * @aliases neo-dev-disable
   */
  public function neoBuildDevDisable() {
    Build::unsetNeoState('dev');
    Build::unsetNeoState('devAsDdev');
    $this->fileSystem->delete($this->getRoot() . '/.git/index.lock');
    $this->output()->writeln(dt('<info>[neo]</info> Automatic tracking of Neo DEV server disabled.'));
  }

  /**
   * Clear twig/template cache.
   *
   * @command neo:build:cc
   * @usage drush neo:build:cc
   *   Clear twig/template cache.
   * @aliases neo-cc
   */
  public function neoBuildClearCache() {
    $this->twig->invalidate();
    Cache::invalidateTags(['rendered']);
    // This is executed based on old/previously known information if $kernel is
    // not passed in, which is sufficient, since new extensions cannot have any
    // primed caches yet.
    // phpcs:disable
    // @phpstan-ignore-next-line
    $module_handler = \Drupal::moduleHandler();
    // Flush all persistent caches.
    $module_handler->invokeAll('cache_flush');
    foreach (Cache::getBins() as $cache_backend) {
      $cache_backend->deleteAll();
    }
    // Clear all plugin caches.
    // @phpstan-ignore-next-line
    \Drupal::service('plugin.cache_clearer')->clearCachedDefinitions();
    // phpcs:enable
  }

  /**
   * Clear twig/template cache.
   *
   * @command neo:build:cli
   * @usage drush neo:build:cli
   *   The build CLI for Neo.
   * @aliases neo-cli
   */
  public function neoBuildCli() {
    $options = [];
    $default = NULL;
    foreach ($this->getAvailableThemes() as $key => $theme) {
      if ($key === 'front') {
        $default = $theme->info['name'];
      }
      $options[$key] = $theme->info['name'];
    }
    /** @var \Drush\Style\DrushStyle $io */
    $io = $this->io();
    $theme = $io->choice('Select a theme', $options, $default);
    $this->output()->writeln($theme);
  }

  /**
   * Get Neo build scopes.
   *
   * @command neo:build:scopes
   * @usage drush neo:build:scopes
   *   Get Neo build scopes.
   * @aliases neo-scopes
   */
  public function neoBuildScopes($options = ['format' => 'table']) {
    $scopes = [];
    foreach ($this->scopeManager->getDefinitions() as $scope => $definition) {
      $scopes[$scope] = [
        'id' => $scope,
        'label' => $definition['label'],
        'description' => $definition['description'],
      ];
    }
    return new RowsOfFields($scopes);
  }

  /**
   * Get Neo build groups.
   *
   * @command neo:build:groups
   * @usage drush neo:build:groups
   *   Get Neo build groups.
   * @aliases neo-groups
   */
  public function neoBuildGroups($options = ['format' => 'table']) {
    $groups = [];
    foreach ($this->groupManager->getDefinitions() as $group => $definition) {
      $groups[$group] = [
        'id' => $group,
        'label' => $definition['label'],
        'description' => $definition['description'],
      ];
    }
    return new RowsOfFields($groups);
  }

  /**
   * Install project build support.
   *
   * @command neo:build:install
   * @usage drush neo:build:install
   *   Install project build support.
   * @aliases neo-install
   */
  public function neoBuildInstall() {
    $moduleDir = $this->moduleExtensionList->getPath('neo_build');
    $docRoot = $this->getRoot();
    if (!$docRoot) {
      $this->output()->writeln(dt('<info>[neo]</info> Neo install failed. Could not find project root.'));
      return;
    }
    $webRoot = $this->getWebRoot();
    $files = [
      'package.json.install' => $docRoot,
      'phpstan.neon.install' => $docRoot,
      'stylelint.config.cjs.install' => $docRoot,
      'prettier.config.cjs.install' => $docRoot,
      'postcss.config.cjs.install' => $docRoot,
      'tailwind.config.cjs.install' => $docRoot,
      'tsconfig.json.install' => $docRoot,
      'vite.config.js.install' => $docRoot,
    ];
    $tokens = [
      '[DOC-ROOT]' => $docRoot,
      '[WEB-ROOT]' => $webRoot,
      '[MODULE-DIR]' => $moduleDir,
      '[NEO-CONFIG-PATH]' => ltrim($this->fileUrlGenerator->generateString($this->neoConfigFileName), '/'),
    ];
    foreach ($files as $filename => $destination) {
      $file = $this->appRoot . '/' . $moduleDir . '/install/neo/' . $filename;
      if (file_exists($file)) {
        $data = file_get_contents($file);
        foreach ($tokens as $token => $value) {
          $data = str_replace($token, $value, $data);
        }
        $this->fileSystem->saveData($data, $destination . '/' . str_replace('.install', '', $filename), FileExists::Replace);
        $this->output()->writeln(dt('<info>[neo]</info> Generated @file.', [
          '@file' => '/' . $filename,
        ]));
      }
    }
    $this->output()->writeln(dt('<info>[neo]</info> Neo is ready. Please run "npm install" from project root.'));
  }

  /**
   * Get the docroot.
   *
   * @return string
   *   The docroot.
   */
  protected function getRoot() {
    if (!isset($this->docRoot)) {
      $this->docRoot = $this->appRoot . '/';
      if (!file_exists($this->docRoot . 'composer.json')) {
        $this->docRoot = $this->appRoot . '/../';
        if (!file_exists($this->docRoot . 'composer.json')) {
          return FALSE;
        }
      }
    }
    return realpath($this->docRoot);
  }

  /**
   * Get the web root.
   */
  protected function getWebRoot() {
    return NestedArray::getValue(json_decode(file_get_contents($this->getRoot() . '/composer.json'), TRUE), [
      'extra',
      'drupal-scaffold',
      'locations',
      'web-root',
    ]) ?? './';
  }

  /**
   * Get scoped themes.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   The scoped themes.
   */
  protected function getScopedThemes(string $scope) {
    $scopes = $this->scopeManager->getDefinitions();
    $available_themes = $this->getAvailableThemes();
    $themes = [];
    foreach ($available_themes as $theme => $extention) {
      if (isset($extention->info['neo'])) {
        if (!empty($extention->info['neo']['scope'])) {
          $theme_scopes = $extention->info['neo']['scope'];
          if (!is_array($theme_scopes)) {
            $theme_scopes = [$theme_scopes];
          }
          foreach ($theme_scopes as $theme_scope) {
            if (isset($scopes[$theme_scope]) && $theme_scope === $scope) {
              $theme_extension = $available_themes[$theme];
              $theme_extension->weight = count($available_themes[$theme]->requires) * -1;
              foreach ($available_themes[$theme]->requires as $theme_key => $theme_data) {
                if (empty($available_themes[$theme_key])) {
                  // May not be a theme as modules as allowed as requirements.
                  continue;
                }
                $required_extention = $available_themes[$theme_key];
                if (is_array($required_extention->info['neo'])) {
                  foreach (['theme', 'base', 'components', 'utilities'] as $layer) {
                    if (isset($required_extention->info['neo'][$layer])) {
                      $theme_extension->info['neo'][$layer] += $required_extention->info['neo'][$layer];
                    }
                  }
                  if (isset($required_extention->info['neo']['safelist'])) {
                    $theme_extension->info['neo']['safelist'] = array_merge($theme_extension->info['neo']['safelist'], $required_extention->info['neo']['safelist']);
                  }
                }
                $themes[$theme_key] = $required_extention;
              }
              $themes[$theme] = $theme_extension;
            }
          }
        }
        else {
          // If no scope is specified, include in all scopes.
          $themes[$theme] = $available_themes[$theme];
        }
      }
    }
    uasort($themes, function ($a, $b) {
      return $a->weight <=> $b->weight;
    });
    return $themes;
  }

  /**
   * Get available themes.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   The available themes.
   */
  protected function getAvailableThemes() {
    $themes = $this->themeExtensionList->reset()->getList();
    foreach ($themes as $extention) {
      if (isset($extention->info['neo'])) {
        if (!is_array($extention->info['neo'])) {
          $extention->info['neo'] = [];
        }
        $extention->info['neo'] += [
          'scope' => 'front',
          'group' => 'custom',
          'theme' => [],
          'base' => [],
          'components' => [],
          'utilities' => [],
          'safelist' => [],
        ];
      }
    }
    return $themes;
  }

}
