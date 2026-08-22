<?php

declare(strict_types=1);

namespace Drupal\neo_build\Commands;

use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Plugin\PluginManagerInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\neo_build\NeoBuild;
use Drupal\neo_build\NeoExtensionList;
use Drupal\neo_build\PrepareNotice;
use Drupal\neo_build\Preparer;
use Drush\Commands\DrushCommands as CoreCommands;
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
    private readonly PluginManagerInterface $scopeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly NeoExtensionList $neoExtensionList,
    private readonly TwigEnvironment $twig,
    private readonly Preparer $preparer,
  ) {
    parent::__construct();
  }

  /**
   * Prepare a scope: write its four build artifacts.
   *
   * Writes neo.json, neo.tsconfig.json and phpstan.neon at the project root,
   * and tailwind.neo.css beside the scope's primary file.
   *
   * @command neo:build
   * @usage drush neo:build
   *   Prepare the front scope.
   * @usage drush neo:build back
   *   Prepare the back scope.
   * @aliases neo
   *
   * @throws \InvalidArgumentException
   *   When the scope does not exist.
   */
  public function neoBuild(string $scope = 'front') {
    $result = $this->preparer->prepare($scope);

    $this->output()->writeln((string) dt('<info>⟢ [neo]</info> Prepare Scope: @scope', [
      '@scope' => $result->getScopeLabel(),
    ]));
    foreach ($result->getNotices() as $notice) {
      $marker = $notice->getType() === PrepareNotice::EXTENSION_ADDED
        ? '<info>✓ [neo]</info>'
        : '<comment>⚠ [neo]</comment>';
      $this->output()->writeln((string) dt($marker . ' @message', [
        '@message' => $notice->getMessage(),
      ]));
    }
    $this->output()->writeln((string) dt('<info>⟢ [neo]</info> Prepare Success'));
    $this->output()->writeln('');
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
    // Remove scopes based on the admin theme.
    $active_theme = \Drupal::service('theme.manager')->getActiveTheme();
    $adminThemeName = \Drupal::config('system.theme')->get('admin');
    if ($active_theme->getName() === $adminThemeName) {
      unset($scopes['front']);
    }
    elseif (!$adminThemeName) {
      unset($scopes['back']);
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
   * Get Neo build status.
   *
   * Reports whether dev (HMR) mode is on, which scope the dev server is
   * serving, and whether a dev server is actually answering — everything a
   * developer or agent needs before deciding whether to run a build at all.
   *
   * @command neo:build:status
   * @usage drush neo:build:status
   *   Show Neo build/dev status.
   * @usage drush neo:build:status --format=json
   *   Machine-readable status.
   * @aliases neo-status
   *
   * @field-labels
   *   status: Status
   *   dev: Dev mode
   *   scope: Dev scope
   *   dev_server_url: Dev server URL
   *   dev_server_up: Dev server answering
   *   lock: Lock file
   */
  public function neoBuildStatus($options = ['format' => 'table']): PropertyList {
    $dev = (bool) NeoBuild::getNeoState('dev', FALSE);
    $scope = NeoBuild::getNeoState('scope', 'front');
    $lock = file_exists($this->getRoot() . '/_neo.lock');
    $up = $this->devServerAnswering();
    if ($dev && $up) {
      $status = 'DEV — assets served via HMR from the dev server (scope: ' . $scope . '). Builds are not needed for ' . $scope . ' changes; other scopes still serve stale dist/.';
    }
    elseif ($dev) {
      $status = 'STALE — dev mode is on but no dev server is answering. Run "npm run deploy" (or "drush neo-dev-disable" + "drush cr") to restore dist/ assets.';
    }
    elseif ($up) {
      $status = 'ORPHANED — a dev server is answering but Drupal is not using it. Restart "npm start" or stop the server.';
    }
    else {
      $status = 'PROD — assets served from compiled dist/.';
    }
    return new PropertyList([
      'status' => $status,
      'dev' => $dev,
      'scope' => $scope,
      'dev_server_url' => NeoBuild::getViteDevServerUrl(),
      'dev_server_up' => $up,
      'lock' => $lock,
    ]);
  }

  /**
   * Probe the vite dev server port.
   *
   * The dev server binds 0.0.0.0 in this container, so a localhost TCP
   * connect is enough to know whether anything is answering.
   *
   * @return bool
   *   TRUE if a dev server is answering.
   */
  protected function devServerAnswering(): bool {
    $port = (int) NeoBuild::getNeoSetting('port', 5173);
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen('localhost', $port, $errno, $errstr, 1);
    if ($socket !== FALSE) {
      fclose($socket);
      return TRUE;
    }
    return FALSE;
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

      $this->output()->writeln((string) dt('<info>✔ [neo]</info> Automatic tracking of Neo DEV server enabled.'));
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
      $this->output()->writeln((string) dt('<info>✔ [neo]</info> Automatic tracking of Neo DEV server disabled.'));
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
    $this->output()->writeln((string) dt('<info>✔ [neo]</info> Build cleanup complete.'));

    // Update compiled versions.
    $config_factory = \Drupal::configFactory();
    $config = $config_factory->getEditable('neo_build.info');
    $extensions = $this->neoExtensionList->all();
    foreach ($extensions as $name => $extension) {
      $config->set('versions.' . $name, $extension->getVersion());
    }

    $config->save();
    $this->output()->writeln((string) dt('<info>✔ [neo]</info> Neo build versions updated.'));
  }

  /**
   * Install project build support.
   *
   * @command neo:build:install
   * @option claude Scaffold the personal Claude Code phpcs hook.
   * @usage drush neo:build:install
   *   Install project build support.
   * @usage drush neo:build:install --claude
   *   Also scaffold the Claude Code phpcs hook (.claude, gitignored).
   * @aliases neo-install
   */
  public function neoBuildInstall(array $options = ['claude' => FALSE]) {
    $root = $this->getRoot();
    if (!$root) {
      $this->output()->writeln((string) dt('<info>[neo]</info> Neo install failed. Could not find project root.'));
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
        $this->output()->writeln((string) dt('<info>[neo]</info> Generated @file.', [
          '@file' => '/' . $finalFilename,
        ]));
      }
    }

    // Scaffold the ddev Nightwatch runner. Unlike the files above this is
    // written only when absent: it is a shell command a site may reasonably
    // tailor, and clobbering someone's edits on every install would be rude.
    // It also has to be executable, which saveData() does not handle.
    $ddevCommand = $root . '/.ddev/commands/web/nightwatch';
    $ddevTemplate = $this->appRoot . '/' . $moduleDir . '/install/neo/nightwatch.install';
    if (file_exists($ddevTemplate) && is_dir($root . '/.ddev')) {
      if (file_exists($ddevCommand)) {
        $this->output()->writeln((string) dt('<info>[neo]</info> Kept existing @file.', [
          '@file' => '.ddev/commands/web/nightwatch',
        ]));
      }
      else {
        $data = file_get_contents($ddevTemplate);
        foreach ($tokens as $token => $value) {
          $data = str_replace($token, $value, $data);
        }
        $ddevDir = dirname($ddevCommand);
        $this->fileSystem->prepareDirectory($ddevDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        $this->fileSystem->saveData($data, $ddevCommand, FileExists::Replace);
        // DDEV only offers a command whose file is executable.
        $this->fileSystem->chmod($ddevCommand, 0755);
        $this->output()->writeln((string) dt('<info>[neo]</info> Generated @file.', [
          '@file' => '.ddev/commands/web/nightwatch',
        ]));
      }
    }

    // Copy Claude Code skills into the project's .claude/skills directory.
    // Skills are aggregated from every enabled module's install/skills
    // directory (keyed by relative path to dedupe), so each module can ship
    // the skills it owns. Neo Build's own path is included explicitly.
    // Iterating the module list (rather than the neo extension list) keeps
    // this side-effect free — it must not trigger neo library processing
    // just to copy files.
    $skillSources = [$moduleDir => $this->appRoot . '/' . $moduleDir . '/install/skills'];
    foreach ($this->moduleHandler->getModuleList() as $module) {
      $modulePath = $module->getPath();
      $skillSources[$modulePath] = $this->appRoot . '/' . $modulePath . '/install/skills';
    }
    foreach ($skillSources as $skillsSource) {
      if (!is_dir($skillsSource)) {
        continue;
      }
      $skillFiles = $this->fileSystem->scanDirectory($skillsSource, '/.*/');
      foreach ($skillFiles as $skillFile) {
        $relative = ltrim(str_replace($skillsSource, '', $skillFile->uri), '/');
        $skillTarget = $root . '/.claude/skills/' . $relative;
        $skillDir = dirname($skillTarget);
        $this->fileSystem->prepareDirectory($skillDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
        $this->fileSystem->copy($skillFile->uri, $skillTarget, FileExists::Replace);
        $this->output()->writeln((string) dt('<info>[neo]</info> Installed skill @file.', [
          '@file' => '.claude/skills/' . $relative,
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
          $this->output()->writeln((string) dt('<info>[neo]</info> Ddev configured for Vite. (requires ddev restart)'));
        }
        else {
          $this->output()->writeln((string) dt('<info>[neo]</info> Ddev already configured for Vite.'));
        }
      }
    }
    catch (\Error $e) {
      $this->output()->writeln((string) dt('<error>' . $e->getMessage() . '</error>'));
    }

    if (getenv('DDEV_PROJECT')) {
      $this->output()->writeln((string) dt('<info>[neo]</info> Neo is ready. Please run "ddev ssh && npm install" from project root.'));
    }
    else {
      $this->output()->writeln((string) dt('<info>[neo]</info> Neo is ready. Please run "npm install" from project root.'));
    }

    $this->output()->writeln((string) dt('<info>  [neo]</info> Setup GrumPHP (optional)'));
    $this->output()->writeln((string) dt('        Run the following commands from the project root:'));
    foreach ([
      'composer require --dev jacerider/grumphp-drupal',
      'ddev exec grumphp git:init',
      'ddev exec grumphp git:pre-commit',
    ] as $command) {
      $this->output()->writeln((string) dt('        - "@command"', [
        '@command' => $command,
      ]));
    }

    if (!empty($options['claude'])) {
      $this->installClaudeHook($root, $moduleDir);
    }
  }

  /**
   * Scaffolds the personal Claude Code phpcs hook.
   *
   * Copies the hook script into .claude/hooks and merges a PostToolUse hook
   * into the developer's local (gitignored) .claude/settings.local.json, so
   * edits to Drupal PHP get linted with phpcs. Idempotent — it never clobbers
   * existing settings, and the config stays out of git.
   *
   * @param string $root
   *   The project root.
   * @param string $moduleDir
   *   The neo_build module path, relative to the app root.
   */
  protected function installClaudeHook(string $root, string $moduleDir): void {
    $template = $this->appRoot . '/' . $moduleDir
      . '/install/claude/phpcs-check.sh';
    if (!file_exists($template)) {
      return;
    }
    $hookDir = $root . '/.claude/hooks';
    $this->fileSystem->prepareDirectory(
      $hookDir,
      FileSystemInterface::CREATE_DIRECTORY
      | FileSystemInterface::MODIFY_PERMISSIONS
    );
    $hookTarget = $hookDir . '/phpcs-check.sh';
    $this->fileSystem->copy($template, $hookTarget, FileExists::Replace);
    $this->fileSystem->chmod($hookTarget, 0755);

    $command = 'bash "${CLAUDE_PROJECT_DIR:-.}/.claude/hooks/phpcs-check.sh"';
    $settingsPath = $root . '/.claude/settings.local.json';
    $settings = [];
    if (file_exists($settingsPath)) {
      $decoded = json_decode(file_get_contents($settingsPath), TRUE);
      if (is_array($decoded)) {
        $settings = $decoded;
      }
    }
    $entries = $settings['hooks']['PostToolUse'] ?? [];
    $present = FALSE;
    foreach ($entries as $entry) {
      foreach ($entry['hooks'] ?? [] as $hook) {
        if (str_contains($hook['command'] ?? '', 'phpcs-check.sh')) {
          $present = TRUE;
        }
      }
    }
    if (!$present) {
      $entries[] = [
        'matcher' => 'Edit|Write',
        'hooks' => [
          [
            'type' => 'command',
            'command' => $command,
            'timeout' => 60,
            'statusMessage' => 'phpcs (Drupal)',
          ],
        ],
      ];
      $settings['hooks']['PostToolUse'] = $entries;
      $json = json_encode(
        $settings,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
      );
      $this->fileSystem->saveData(
        $json . "\n",
        $settingsPath,
        FileExists::Replace
      );
    }

    $this->ensureClaudeGitignore($root);
    $this->output()->writeln((string) dt(
      '<info>[neo]</info> Installed Claude Code phpcs hook (personal).'
    ));
  }

  /**
   * Ensures the personal Claude Code config is gitignored.
   *
   * @param string $root
   *   The project root.
   */
  protected function ensureClaudeGitignore(string $root): void {
    $path = $root . '/.gitignore';
    $data = file_exists($path) ? file_get_contents($path) : '';
    if (str_contains($data, '/.claude/settings.local.json')) {
      return;
    }
    $data .= "\n# Claude Code personal config (not shared with the team)\n"
      . "/.claude/settings.local.json\n/.claude/hooks/\n";
    $this->fileSystem->saveData($data, $path, FileExists::Replace);
  }

  /**
   * Clone a template into a theme from neo_base.
   *
   * @command neo:template
   * @usage drush neo:template
   *   Run the neoBuild template generator.
   * @aliases neo-template
   *
   * @throws \Exception
   *   If no index or no server were passed or passed values are invalid.
   */
  public function neoTemplate() {
    $themeId = $this->io()->choice((string) dt('Select the theme:'), [
      'front' => (string) dt('Front Theme'),
      'back' => (string) dt('Back Theme'),
    ]);
    $baseTheme = $this->themeExtensionList->get('neo_base');
    $baseThemePath = $baseTheme->getPath();
    $theme = $this->themeExtensionList->get($themeId);
    $themePath = $theme->getPath();
    /** @var \Drupal\Core\File\FileSystemInterface $file_system */
    $fileSystem = \Drupal::service('file_system');
    $files = $fileSystem->scanDirectory($baseThemePath . '/templates', '/.*\.html.twig.example$/');
    if (!$files) {
      $this->io()->error((string) dt('No template files found.'));
      return;
    }
    $fileOptions = [];
    foreach ($files as $file) {
      $fileOptions[$file->uri] = str_replace('.example', '', $file->filename);
    }
    $fileUri = $this->io()->choice((string) dt('Select the template file:'), $fileOptions);

    $variationName = $this->io()->ask('Template variation name', required: TRUE, validate: function ($answer) {
      if (!preg_match('/^[\-a-z]+[\-a-z0-9]*$/', $answer)) {
        return 'Only lowercase alphanumeric chars/dashes allowed; only letters/dashes allowed as first character.';
      }
    });

    $destination = str_replace($baseThemePath, $themePath, $fileUri);
    $destination = str_replace('.html.twig.example', '--' . $variationName . '.html.twig', $destination);

    if (file_exists($destination)) {
      $this->io()->error((string) dt('The template file @file already exists.', ['@file' => $destination]));
      return;
    }

    $fileSystem->prepareDirectory(dirname($destination), FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $fileSystem->copy($fileUri, $destination);
    $this->io()->success((string) dt('Template file @file created successfully.', ['@file' => $destination]));
  }

  /**
   * Get the web root.
   */
  protected function getDocRoot() {
    $composerData = json_decode(file_get_contents($this->getRoot() . '/composer.json'), TRUE);
    return str_replace('./', '', NestedArray::getValue($composerData, [
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
