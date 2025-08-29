<?php

declare(strict_types=1);

namespace Drupal\neo_build\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Asset\LibraryDiscovery;
use Drupal\Core\Asset\LibraryDiscoveryCollector;
use Drupal\neo_build\NeoBuild;
use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\neo_build\NeoBuildCollection;
use Drupal\neo_build\NeoExtensionList;
use Drush\Commands\DrushCommands as CoreCommands;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\neo_build\Event\NeoBuildEvent;
use Drupal\neo_build\NeoCss;
use Symfony\Component\Yaml\Yaml;

/**
 * Drush commands for Neo Build.
 */
class DrushCommands extends CoreCommands {

  /**
   * The doc root.
   *
   * @var string
   */
  protected $root;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    private readonly string $appRoot,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly PluginManagerInterface $scopeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly NeoExtensionList $neoExtensionList,
    private readonly TwigEnvironment $twig,
  ) {
    parent::__construct();
  }

  /**
   * Generate neo.json.
   *
   * @command neo:build
   * @usage drush neo:build
   *   Run the neoBuild build generator.
   * @aliases neo
   *
   * @throws \Exception
   *   If no index or no server were passed or passed values are invalid.
   */
  public function neoBuild(string $scope = 'front') {
    if (!$this->scopeManager->hasDefinition($scope)) {
      throw new \Exception("Scope '$scope' does not exist.");
    }

    // Clear extension information.
    $this->moduleExtensionList->reset();
    $this->themeHandler->refreshInfo();

    $scopeDefinition = $this->scopeManager->getDefinition($scope);
    $this->output()->writeln(dt('<info>⟢ [neo]</info> Prepare Scope: @scope', [
      '@scope' => $scopeDefinition['label'],
    ]));

    NeoBuild::setNeoState('scope', $scope);
    // Example: /Users/jacerider/Sites/augustAsh/rhls.
    $root = $this->getRoot();
    // Example: web/.
    $docRoot = $this->getDocRoot();

    if ($this->libraryDiscovery instanceof LibraryDiscoveryCollector) {
      $this->libraryDiscovery->clear();
    }
    elseif ($this->libraryDiscovery instanceof LibraryDiscovery) {
      $this->libraryDiscovery->clearCachedDefinitions();
    }
    else {
      throw new \Exception('Library discovery service is not supported.');
    }
    NeoBuild::preventAlter();

    $collection = new NeoBuildCollection(
      $this->output(),
      NeoBuild::getNeoSetting('host'),
      NeoBuild::getNeoSetting('port'),
      NeoBuild::getNeoSetting('https'),
      NeoBuild::getNeoState('dev', FALSE),
      $root,
      $docRoot,
      $this->moduleHandler->getModule('neo_build')->getPath(),
    );
    $collection->setScope($scope);

    $extensions = $this->neoExtensionList->all();
    foreach ($extensions as $extension) {
      $collection->addStanPath($extension);
    }

    $scopedExtensions = $this->neoExtensionList->all([$scope]);
    foreach ($scopedExtensions as $extension) {
      if ($extension->getType() === 'module') {
        $collection->addModule($extension);
      }
      else {
        $collection->addTheme($extension);
      }
    }

    $event = new NeoBuildEvent($collection, $scopedExtensions);
    $this->eventDispatcher->dispatch($event, NeoBuildEvent::EVENT_NAME);

    NeoBuild::preventAlter(FALSE);
    if ($this->libraryDiscovery instanceof LibraryDiscoveryCollector) {
      $this->libraryDiscovery->clear();
    }
    elseif ($this->libraryDiscovery instanceof LibraryDiscovery) {
      $this->libraryDiscovery->clearCachedDefinitions();
    }

    $this->buildTailwindCss($collection);

    $this->fileSystem->saveData($collection->toNeoJson(), $root . '/neo.json', FileExists::Replace);
    $this->fileSystem->saveData($collection->toTsJson(), $root . '/neo.tsconfig.json', FileExists::Replace);
    $this->fileSystem->saveData($collection->toStanYaml(), $root . '/phpstan.neon', FileExists::Replace);

    $this->output()->writeln(dt('<info>⟢ [neo]</info> Prepare Success'));
    $this->output()->writeln('');

    Cache::invalidateTags(['exo_build:build']);
  }

  /**
   * Builds the Tailwind CSS file.
   *
   * @param \Drupal\neo_build\NeoBuildCollection $collection
   *   The Neo build collection.
   *
   * @throws \Exception
   *   If the primary file is not set or if the root directory is not valid.
   */
  private function buildTailwindCss(NeoBuildCollection $collection) {
    $primaryFile = $collection->getPrimaryFile();
    $primaryDir = dirname($primaryFile);
    $root = $collection->getRoot();
    $docRoot = $collection->getDocRoot();
    $neoRoot = $collection->getNeoRoot();
    $primaryCssPath = $root . $docRoot . $primaryDir . '/tailwind.neo.css';
    $pluginPath = $root . $docRoot . $neoRoot . 'tools/neo-tailwind-plugin.ts';

    $css = new NeoCss();

    // Imports.
    $imports = array_map(fn($path) => $root . $docRoot . $path, $collection->getTailwindImports());
    $css->addImports($imports);
    $collection->clearTailwindImports();

    // Sources.
    $sources = array_map(fn($path) => $root . $docRoot . $path, $collection->getTailwindSources());
    $css->addSources($sources);
    $collection->clearTailwindSources();

    // Add all CSS variables to CSS file and remove them from the collection.
    // Anything that remains in theme will be handled at compile via our plugin.
    foreach ($collection->getTailwindTheme() as $key => $value) {
      if (substr($key, 0, 2) === '--' && is_string($value)) {
        $css->addCssVariable($key, $value);
        $collection->clearTailwindThemeItem($key);
      }
    }

    // All base styles are added to the CSS file.
    foreach ($collection->getTailwindBase() as $key => $value) {
      if (substr($key, 0, 2) === '--' && is_string($value)) {
        $css->addCssVariable($key, $value, 'base');
      }
      else {
        $css->addRule($key, $value, NULL, 'base');
      }
    }
    $collection->clearTailwindBase();

    // All component are added to the CSS file.
    foreach ($collection->getTailwindComponents() as $key => $value) {
      if (substr($key, 0, 2) === '--' && is_string($value)) {
        $css->addCssVariable($key, $value, 'components');
      }
      else {
        $css->addRule($key, $value, NULL, 'components');
      }
    }
    $collection->clearTailwindComponents();

    foreach ($collection->getTailwindUtilities() as $key => $value) {
      $css->addUtility($key, $value);
    }
    $collection->clearTailwindUtilities();

    // Variants are added to the CSS file.
    foreach ($collection->getTailwindVariants() as $name => $selectors) {
      $css->addVariant($name, $selectors);
    }
    $collection->clearTailwindVariants();

    $output = "/*\n";
    $output .= " * NEO Tailwind CSS\n";
    $output .= " * Generated by Neo Build. Do NOT edit this file directly.\n";
    $output .= " */\n\n";
    $output .= "/* NEO Plugin */\n";
    $output .= "@plugin \"$pluginPath\";\n\n";
    $output .= $css->toCss();

    $this->fileSystem->saveData($output, $primaryCssPath, FileExists::Replace);
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
    // Flush all persistent caches.
    $this->moduleHandler->invokeAll('cache_flush');
    // Try to figure out the cache bins that need to be cleared.
    $bins = array_filter(Cache::getBins(), fn ($id) => in_array($id, [
      // 'static',
      // 'bootstrap',
      // 'config',
      // 'default',
      // 'entity',
      // 'menu',
      'render',
      // 'access_policy',
      // 'data',
      // 'discovery',
      'dynamic_page_cache',
      // 'migrate',
      // 'discovery_migration',
      // 'neo_config_file',
      'page',
      // 'rest',
    ]), ARRAY_FILTER_USE_KEY);
    foreach ($bins as $cache_backend) {
      $cache_backend->deleteAll();
    }
    // Clear all plugin caches.
    \Drupal::service('plugin.cache_clearer')->clearCachedDefinitions();
    // phpcs:enable
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
   * Get vite dev server status.
   *
   * @return bool
   *   Returns TRUE if in dev mode.
   */
  public function neoBuildDevEnabled() {
    return NeoBuild::getNeoState('dev', FALSE);
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
    if (!$this->neoBuildDevEnabled()) {
      NeoBuild::setNeoState('dev', TRUE);
      $root = $this->getRoot();

      // Set pre-commit hook.
      $moduleDir = $this->moduleExtensionList->getPath('neo_build');
      $file = $this->appRoot . '/' . $moduleDir . '/git.pre-commit.txt';
      $data = file_get_contents($file);
      $this->fileSystem->saveData($data, $root . '/.git/hooks/pre-commit', FileExists::Replace);
      $this->fileSystem->chmod($root . '/.git/hooks/pre-commit', 0777);

      // Set lock file.
      $this->fileSystem->saveData('', $root . '/_neo.lock', FileExists::Replace);

      $this->output()->writeln(dt('<info>✔ [neo]</info> Automatic tracking of Neo DEV server enabled.'));
    }
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
    if ($this->neoBuildDevEnabled()) {
      NeoBuild::unsetNeoState('dev');
      $this->output()->writeln(dt('<info>✔ [neo]</info> Automatic tracking of Neo DEV server disabled.'));
    }
  }

  /**
   * Cleanup files created during build.
   *
   * @command neo:build:dev:cleanup
   * @usage drush neo:build:dev:cleanup
   *   Cleanup files created during build.
   * @aliases neo-dev-cleanup
   */
  public function neoBuildCleanup() {
    $root = $this->getRoot();
    $this->fileSystem->delete($root . '/.git/hooks/pre-commit');
    $this->fileSystem->delete($root . '/_neo.lock');
    $this->output()->writeln('');
    $this->output()->writeln(dt('<info>✔ [neo]</info> Build cleanup complete.'));

    // Update compiled versions.
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('neo_build.info');
    $extensions = $this->neoExtensionList->all();
    foreach ($extensions as $name => $extension) {
      $config->set('versions.' . $name, $extension->getVersion());
    }

    $config->save();
    $this->output()->writeln(dt('<info>✔ [neo]</info> Neo build versions updated.'));
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
    $root = $this->getRoot();
    if (!$root) {
      $this->output()->writeln(dt('<info>[neo]</info> Neo install failed. Could not find project root.'));
      return;
    }
    $docRoot = $this->getDocRoot();
    $moduleDir = $this->moduleExtensionList->getPath('neo_build');
    $files = [
      'package.json.install' => $root,
      'tsconfig.json.install' => $root,
      'vite.config.ts.install' => $root,
    ];
    $tokens = [
      '[ROOT]' => $root,
      '[DOC-ROOT]' => $docRoot,
      '[MODULE-DIR]' => $moduleDir,
    ];
    foreach ($files as $filename => $destination) {
      $file = $this->appRoot . '/' . $moduleDir . '/install/neo/' . $filename;
      if (file_exists($file)) {
        $data = file_get_contents($file);
        foreach ($tokens as $token => $value) {
          $data = str_replace($token, $value, $data);
        }
        $finalFilename = str_replace('.install', '', $filename);
        $this->fileSystem->saveData($data, $destination . '/' . $finalFilename, FileExists::Replace);
        $this->output()->writeln(dt('<info>[neo]</info> Generated @file.', [
          '@file' => '/' . $finalFilename,
        ]));
      }
    }

    // Update .ddev.
    try {
      $path = $root . '/.ddev/config.yaml';
      if (file_exists($path)) {
        $config = Yaml::parseFile($path);
        if (!isset($config['web_extra_exposed_ports'])) {
          $config['web_extra_exposed_ports'] = [];
        }
        $hasVite = array_filter($config['web_extra_exposed_ports'], function ($item) {
          return isset($item['name']) && $item['name'] === 'vite';
        });
        if (!$hasVite) {
          $config['web_extra_exposed_ports'][] = [
            'name' => 'vite',
            'container_port' => 5173,
            'http_port' => 5172,
            'https_port' => 5173,
          ];
          $this->fileSystem->saveData(Yaml::dump($config, 2, 2), $path, FileExists::Replace);
          $this->output()->writeln(dt('<info>[neo]</info> Ddev configured for Vite. (requires ddev restart)'));
        }
        else {
          $this->output()->writeln(dt('<info>[neo]</info> Ddev already configured for Vite.'));
        }
      }
    }
    catch (\Error $e) {
      $this->output()->writeln(dt('<error>' . $e->getMessage() . '</error>'));
    }

    if (getenv('DDEV_PROJECT')) {
      $this->output()->writeln(dt('<info>[neo]</info> Neo is ready. Please run "ddev ssh && npm install" from project root.'));
    }
    else {
      $this->output()->writeln(dt('<info>[neo]</info> Neo is ready. Please run "npm install" from project root.'));
    }

    $this->output()->writeln(dt('<info>  [neo]</info> Setup GrumPHP (optional)'));
    $this->output()->writeln(dt('        Run the following commands from the project root:'));
    foreach ([
      'composer require --dev jacerider/grumphp-drupal',
      'ddev exec grumphp git:init',
      'ddev exec grumphp git:pre-commit',
    ] as $command) {
      $this->output()->writeln(dt('        - "@command"', [
        '@command' => $command,
      ]));
    }
  }

  /**
   * Get the web root.
   */
  protected function getDocRoot() {
    return str_replace('./', '', NestedArray::getValue(json_decode(file_get_contents($this->getRoot() . '/composer.json'), TRUE), [
      'extra',
      'drupal-scaffold',
      'locations',
      'web-root',
    ]) ?? '/') . '/';
  }

  /**
   * Get the docroot.
   *
   * @return string
   *   The docroot.
   */
  private function getRoot() {
    if (!isset($this->root)) {
      $this->root = $this->appRoot . '/';
      if (!file_exists($this->root . 'composer.json')) {
        $this->root = $this->appRoot . '/../';
        if (!file_exists($this->root . 'composer.json')) {
          return FALSE;
        }
      }
    }
    return realpath($this->root);
  }

}
