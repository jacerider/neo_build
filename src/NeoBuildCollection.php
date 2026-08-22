<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Component\Utility\NestedArray;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Object representing vite manifest.
 */
class NeoBuildCollection {

  /**
   * The output interface.
   *
   * @var \Symfony\Component\Console\Output\OutputInterface
   */
  private OutputInterface $output;

  /**
   * The data collection.
   *
   * @var array
   */
  private array $neo = [
    'host' => NULL,
    'port' => NULL,
    'https' => NULL,
    'dev' => FALSE,
    'root' => NULL,
    'docRoot' => NULL,
    'neoRoot' => NULL,
    'primaryRoot' => NULL,
    'primaryFile' => NULL,
    'scope' => 'front',
    'ignored' => [
      '**/.ddev/**/*',
      '**/web/core/**/*',
      '**/web/profiles/**/*',
      '**/web/sites/**/*',
    ],
    'tailwind' => [
      'source' => [],
      'import' => [],
      'base' => [],
      'theme' => [
        'extend' => [],
        'colors' => [],
        'fontFamily' => [],
      ],
      'utilities' => [],
      'components' => [],
      'variants' => [],
      'icon_libraries' => [],
      'icons' => [],
    ],
    'vite' => [
      'lib' => [],
    ],
    'stylelint' => [],
  ];

  /**
   * The TypeScript configuration data.
   *
   * @var array
   */
  private array $ts = [
    'include' => [],
  ];

  /**
   * Constructs a new NeoBuildCollection object.
   *
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output interface.
   * @param string $host
   *   The host.
   * @param int $port
   *   The port.
   * @param bool $https
   *   Whether to use HTTPS.
   * @param bool $dev
   *   Whether this is a development build.
   * @param string $root
   *   The root path.
   * @param string $docRoot
   *   The document root path.
   * @param string $neoRoot
   *   The Neo root path.
   */
  public function __construct(
    OutputInterface $output,
    string $host,
    int $port,
    bool $https,
    bool $dev,
    string $root,
    string $docRoot,
    string $neoRoot,
  ) {
    $this->output = $output;
    $this->setHost($host);
    $this->setPort($port);
    $this->setHttps($https);
    $this->setDev($dev);
    $this->setRoot($root);
    $this->setDocRoot($docRoot);
    $this->setNeoRoot($neoRoot);

    // Add Neo build typing.
    $this->addTsInclude($neoRoot . '/src/js/typings/*.d.ts');
  }

  /**
   * Sets the host.
   *
   * @param string $host
   *   The host to set.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setHost(string $host): self {
    $this->neo['host'] = $host;
    return $this;
  }

  /**
   * Gets the host.
   *
   * @return string
   *   The host.
   */
  public function getHost(): string {
    return $this->neo['host'];
  }

  /**
   * Sets the port.
   *
   * @param int $port
   *   The port to set.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setPort(int $port): self {
    $this->neo['port'] = $port;
    return $this;
  }

  /**
   * Gets the port.
   *
   * @return int
   *   The port.
   */
  public function getPort(): int {
    return $this->neo['port'];
  }

  /**
   * Sets the HTTPS flag.
   *
   * @param bool $https
   *   Whether to use HTTPS.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setHttps(bool $https): self {
    $this->neo['https'] = $https;
    return $this;
  }

  /**
   * Gets the HTTPS flag.
   *
   * @return bool
   *   Whether to use HTTPS.
   */
  public function getHttps(): bool {
    return $this->neo['https'];
  }

  /**
   * Sets the development mode flag.
   *
   * @param bool $dev
   *   Whether this is a development build.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setDev(bool $dev): self {
    $this->neo['dev'] = $dev;
    return $this;
  }

  /**
   * Checks if this is a development build.
   *
   * @return bool
   *   TRUE if this is a development build, FALSE otherwise.
   */
  public function isDev(): bool {
    return $this->neo['dev'];
  }

  /**
   * Sets the root path.
   *
   * @param string $root
   *   The root path to set.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setRoot(string $root): self {
    $this->neo['root'] = rtrim($root, '/') . '/';
    return $this;
  }

  /**
   * Gets the root path.
   *
   * @return string
   *   The root path.
   */
  public function getRoot(): string {
    return $this->neo['root'];
  }

  /**
   * Sets the document root path.
   *
   * @param string $docRoot
   *   The document root path to set.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setDocRoot(string $docRoot): self {
    $this->neo['docRoot'] = rtrim($docRoot, '/') . '/';
    return $this;
  }

  /**
   * Gets the document root path.
   *
   * @return string
   *   The document root path.
   */
  public function getDocRoot(): string {
    return $this->neo['docRoot'];
  }

  /**
   * Sets the Neo root path.
   *
   * @param string $neoRoot
   *   The Neo root path to set.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setNeoRoot(string $neoRoot): self {
    $this->neo['neoRoot'] = rtrim($neoRoot, '/') . '/';
    return $this;
  }

  /**
   * Gets the Neo root path.
   *
   * @return string
   *   The Neo root path.
   */
  public function getNeoRoot(): string {
    return $this->neo['neoRoot'];
  }

  /**
   * Gets the relative root path.
   *
   * @return string
   *   The relative root path prefixed with './'.
   */
  public function getRelativeRoot(): string {
    return './' . $this->getDocRoot();
  }

  /**
   * Sets the scope.
   *
   * @param string $scope
   *   The scope to set. If not in dev mode, will be forced to 'front'.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setScope(string $scope): self {
    // If we are not in DEV mode, set scope to 'front'.
    $scope = $this->neo['dev'] ? $scope : 'front';
    $this->neo['scope'] = $scope;
    return $this;
  }

  /**
   * Gets the current scope.
   *
   * @return string
   *   The current scope.
   */
  public function getScope(): string {
    return $this->neo['scope'];
  }

  /**
   * Gets the globs the build ignores.
   *
   * @return string[]
   *   The ignored globs.
   */
  public function getIgnored(): array {
    return $this->neo['ignored'];
  }

  /**
   * Adds a TypeScript include path.
   *
   * @param string $path
   *   The path to include in TypeScript configuration.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTsInclude(string $path): self {
    $this->ts['include'][] = $this->getRelativeRoot() . $path;
    return $this;
  }

  /**
   * Gets the TypeScript include paths, in the order they were added.
   *
   * @return string[]
   *   The include paths, each prefixed with the relative root.
   */
  public function getTsIncludes(): array {
    return $this->ts['include'];
  }

  /**
   * Adds an extension to the collection.
   *
   * @param \Drupal\neo_build\NeoExtension $extension
   *   The extension to add.
   */
  private function addExtension(NeoExtension $extension) {
    $this->output->writeln(dt('<info>✓ [neo]</info> Extension added: @name (@id)', [
      '@name' => $extension->getLabel(),
      '@id' => $extension->getName(),
    ]));
    $this->addTailwindFromExtension($extension);

    foreach ($extension->getLibraries() as $library) {
      $this->addViteLibFromLibrary($library);
      $this->addStylelintFromLibrary($library);
    }
  }

  /**
   * Adds a module extension.
   *
   * @param \Drupal\neo_build\NeoExtension $extension
   *   The module extension to add.
   */
  public function addModule(NeoExtension $extension) {
    $this->addExtension($extension);
  }

  /**
   * Adds a theme extension.
   *
   * @param \Drupal\neo_build\NeoExtension $extension
   *   The theme extension to add.
   */
  public function addTheme(NeoExtension $extension) {
    $this->addExtension($extension);
  }

  /**
   * Add Tailwind path from extension.
   *
   * @param \Drupal\neo_build\NeoExtension $extension
   *   The extension to add the Tailwind path for.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  protected function addTailwindFromExtension(NeoExtension $extension): self {
    $path = $extension->getPath();
    foreach ($extension->getLibraries() as $libraryId => $library) {
      if ($library->isImport()) {
        $this->addTailwindImport($extension->getName() . ':' . $libraryId, $library->getCssPath());
      }
    }
    $this->addTailwindSource($extension->getName() . ':Files', $path . '/src/**/*.{js,ts,jsx,tsx,php}');
    $this->addTailwindSource($extension->getName() . ':Module', $path . '/*.{module,inc,theme}');
    if (is_dir($path . '/templates')) {
      $this->addTailwindSource($extension->getName() . ':Twig', $path . '/templates/**/*.twig');
    }
    foreach ($extension->getTailwindInfo() as $key => $data) {
      $method = 'addTailwind' . ucfirst($key);
      if (method_exists($this, $method)) {
        $this->$method($data);
      }
    }
    return $this;
  }

  /**
   * Add Tailwind source.
   *
   * @param string $id
   *   The unique ID for the Tailwind path. Typically extensionName:type.
   *   Passing an $id that is already used will overwrite the existing path.
   * @param string $path
   *   The path to add.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindSource(string $id, string $path): self {
    $this->neo['tailwind']['source'][$id] = $path;
    return $this;
  }

  /**
   * Get Tailwind sources.
   *
   * @return array
   *   An array of Tailwind source paths.
   */
  public function getTailwindSources(): array {
    return $this->neo['tailwind']['source'] ?? [];
  }

  /**
   * Clears all Tailwind sources.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function clearTailwindSources(): self {
    $this->neo['tailwind']['source'] = [];
    return $this;
  }

  /**
   * Add Tailwind import.
   *
   * These paths will be imported into the main .css via @import.
   *
   * @param string $id
   *   The unique ID for the Tailwind path. Typically extensionName:type.
   *   Passing an $id that is already used will overwrite the existing path.
   * @param string $path
   *   The path to add.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindImport(string $id, string $path): self {
    $this->neo['tailwind']['import'][$id] = $path;
    return $this;
  }

  /**
   * Gets all Tailwind imports.
   *
   * @return array
   *   An array of Tailwind import paths.
   */
  public function getTailwindImports(): array {
    return $this->neo['tailwind']['import'] ?? [];
  }

  /**
   * Clears all Tailwind imports.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function clearTailwindImports(): self {
    $this->neo['tailwind']['import'] = [];
    return $this;
  }

  /**
   * Adds Tailwind theme data.
   *
   * @param array $data
   *   The theme data to add.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindTheme(array $data): self {
    $this->neo['tailwind']['theme'] = NestedArray::mergeDeep($this->neo['tailwind']['theme'] ?? [], $data);
    return $this;
  }

  /**
   * Adds a single Tailwind theme item.
   *
   * @param string $key
   *   The theme key.
   * @param string $value
   *   The theme value.
   * @param string $position
   *   The position in the theme array (default is 'default').
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindThemeItem(string $key, string $value, ?string $position = NULL): self {
    switch ($position) {
      case 'before':
        $this->neo['tailwind']['theme'] = array_merge([$key => $value], $this->neo['tailwind']['theme'] ?? []);
        break;

      case 'after':
        $this->neo['tailwind']['theme'] = array_merge($this->neo['tailwind']['theme'] ?? [], [$key => $value]);
        break;

      default:
        $this->neo['tailwind']['theme'][$key] = $value;
        break;
    }
    return $this;
  }

  /**
   * Gets the Tailwind theme configuration.
   *
   * @return array
   *   The Tailwind theme data.
   */
  public function getTailwindTheme(): array {
    return $this->neo['tailwind']['theme'] ?? [];
  }

  /**
   * Clears a specific Tailwind theme item.
   *
   * @param string $key
   *   The theme key to clear.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function clearTailwindThemeItem(string $key): self {
    if (isset($this->neo['tailwind']['theme'][$key])) {
      unset($this->neo['tailwind']['theme'][$key]);
    }
    return $this;
  }

  /**
   * Adds Tailwind base styles.
   *
   * @param array $data
   *   The base style data to add.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindBase(array $data): self {
    $this->neo['tailwind']['base'] = NestedArray::mergeDeep($this->neo['tailwind']['base'] ?? [], $data);
    return $this;
  }

  /**
   * Gets the Tailwind base styles.
   *
   * @return array
   *   The Tailwind base style data.
   */
  public function getTailwindBase(): array {
    return $this->neo['tailwind']['base'] ?? [];
  }

  /**
   * Clears all Tailwind base styles.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function clearTailwindBase(): self {
    $this->neo['tailwind']['base'] = [];
    return $this;
  }

  /**
   * Adds Tailwind components.
   *
   * @param array $data
   *   The component data to add.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindComponents(array $data): self {
    $this->neo['tailwind']['components'] = NestedArray::mergeDeep($this->neo['tailwind']['components'] ?? [], $data);
    return $this;
  }

  /**
   * Gets the Tailwind components.
   *
   * @return array
   *   The Tailwind component data.
   */
  public function getTailwindComponents(): array {
    return $this->neo['tailwind']['components'] ?? [];
  }

  /**
   * Clears all Tailwind components.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function clearTailwindComponents(): self {
    $this->neo['tailwind']['components'] = [];
    return $this;
  }

  /**
   * Adds Tailwind utilities.
   *
   * @param array $data
   *   The utility data to add.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindUtilities(array $data): self {
    $this->neo['tailwind']['utilities'] = NestedArray::mergeDeep($this->neo['tailwind']['utilities'] ?? [], $data);
    return $this;
  }

  /**
   * Adds a Tailwind utility.
   *
   * @param string $key
   *   The utility key.
   * @param array $properties
   *   The utility properties.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindUtility(string $key, array $properties): self {
    return $this->addTailwindUtilities([
      $key => $properties,
    ]);
  }

  /**
   * Gets the Tailwind utilities.
   *
   * @return array
   *   The Tailwind utility data.
   */
  public function getTailwindUtilities(): array {
    return $this->neo['tailwind']['utilities'] ?? [];
  }

  /**
   * Clears all Tailwind utilities.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function clearTailwindUtilities(): self {
    $this->neo['tailwind']['utilities'] = [];
    return $this;
  }

  /**
   * Adds Tailwind variants.
   *
   * @param array $data
   *   The variant data to add.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindVariants(array $data): self {
    $this->neo['tailwind']['variants'] = NestedArray::mergeDeep($this->neo['tailwind']['variants'] ?? [], $data);
    return $this;
  }

  /**
   * Clears all Tailwind variants.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function clearTailwindVariants(): self {
    $this->neo['tailwind']['variants'] = [];
    return $this;
  }

  /**
   * Gets the Tailwind variants.
   *
   * @return array
   *   The Tailwind variant data.
   */
  public function getTailwindVariants(): array {
    return $this->neo['tailwind']['variants'] ?? [];
  }

  /**
   * Adds a Tailwind icon.
   *
   * @param string $name
   *   The icon name.
   * @param string $library
   *   The icon library.
   * @param string $hex
   *   The icon hex value.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addTailwindIcon(string $name, string $library, string $hex): self {
    $this->neo['tailwind']['icon_libraries'][$library] = $library;
    $this->neo['tailwind']['icons'][$name] = $hex;
    return $this;
  }

  /**
   * Gets the Tailwind icon libraries.
   *
   * @return string[]
   *   The icon library names, keyed by themselves.
   */
  public function getTailwindIconLibraries(): array {
    return $this->neo['tailwind']['icon_libraries'] ?? [];
  }

  /**
   * Gets the Tailwind icons.
   *
   * @return string[]
   *   Icon hex values keyed by icon name.
   */
  public function getTailwindIcons(): array {
    return $this->neo['tailwind']['icons'] ?? [];
  }

  /**
   * Checks if a CSS file contains Tailwind base imports.
   *
   * @param string $cssPath
   *   The path to the CSS file.
   *
   * @return bool
   *   TRUE if the file contains Tailwind base CSS imports, FALSE otherwise.
   */
  protected function isTailwindBaseCss(string $cssPath): bool {
    $data = file_get_contents($cssPath);
    if (preg_match('/^@import\s+["\']tailwindcss["\'](.*);?$/m', $data)) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Sets the primary root path.
   *
   * @param string $path
   *   The primary root path.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setPrimaryRoot(string $path): self {
    $this->neo['primaryRoot'] = $path;
    return $this;
  }

  /**
   * Gets the primary root path.
   *
   * @return string|null
   *   The primary root path, or NULL while no primary file has been found.
   */
  public function getPrimaryRoot(): ?string {
    return $this->neo['primaryRoot'];
  }

  /**
   * Sets the primary file path.
   *
   * @param string $path
   *   The primary file path.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function setPrimaryFile(string $path): self {
    $this->neo['primaryFile'] = $path;
    return $this;
  }

  /**
   * Gets the primary file path.
   *
   * The primary file is the scope's CSS entrypoint that imports "tailwindcss";
   * the CSS artifact is written beside it.
   *
   * @return string|null
   *   The primary file path, or NULL while none has been found.
   */
  public function getPrimaryFile(): ?string {
    return $this->neo['primaryFile'];
  }

  /**
   * Adds Vite library configuration from a Neo library.
   *
   * @param \Drupal\neo_build\NeoLibrary $library
   *   The library to add Vite configuration for.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  protected function addViteLibFromLibrary(NeoLibrary $library): self {
    if ($path = $library->getCssPath()) {
      if (!file_exists($path)) {
        $this->output->writeln(dt('<comment>⚠ [neo]</comment> Missing CSS file skipped: @path (@id)', [
          '@path' => $path,
          '@id' => $library->id(),
        ]));
      }
      // Libraries set for import do not need to be handled by Vite as they
      // will be imported into the main CSS file.
      elseif (!$library->isImport()) {
        // If data has string @import "tailwindcss" this is the primary css.
        if ($this->isTailwindBaseCss($path)) {
          $this->setPrimaryRoot($library->getExtension()->getPath());
          $this->setPrimaryFile($path);
        }
        $this->addViteLib($library->id() . ':Css', $path);
      }
    }
    if ($path = $library->getJsPath()) {
      if (!file_exists($path)) {
        $this->output->writeln(dt('<comment>⚠ [neo]</comment> Missing JS file skipped: @path (@id)', [
          '@path' => $path,
          '@id' => $library->id(),
        ]));
        return $this;
      }
      $this->addViteLib($library->id() . ':Js', $path);
      if (substr($path, -3) === '.ts') {
        $this->addTsInclude($path);
        $typingPath = $library->getExtension()->getPath() . '/src/js/typings';
        if (is_dir($typingPath)) {
          $this->addTsInclude($typingPath . '/*.d.ts');
        }
      }
    }
    return $this;
  }

  /**
   * Add a Vite library path.
   *
   * @param string $id
   *   A unique ID for the library. Typically extensionName:libraryName.
   * @param string $path
   *   The library path.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addViteLib(string $id, string $path): self {
    $this->neo['vite']['lib'][$id] = './' . $path;
    return $this;
  }

  /**
   * Gets the Vite library paths.
   *
   * @return string[]
   *   The paths, keyed by library id, each prefixed with './'.
   */
  public function getViteLibs(): array {
    return $this->neo['vite']['lib'] ?? [];
  }

  /**
   * Add to stylelint paths from library.
   *
   * @param \Drupal\neo_build\NeoLibrary $library
   *   The library to add stylelint paths for.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  protected function addStylelintFromLibrary(NeoLibrary $library): self {
    if ($path = $library->getCssPath()) {
      if ($library->getType() === 'theme') {
        $this->addStylelint($library->getExtension()->getName(), dirname($path));
      }
      else {
        $this->addStylelint($library->id(), $path);
      }
    }
    return $this;
  }

  /**
   * Add a stylelint path.
   *
   * @param string $id
   *   A unique ID for the library. Typically extensionName:libraryName.
   * @param string $path
   *   The library path.
   *
   * @return $this
   *   The current instance for method chaining.
   */
  public function addStylelint(string $id, string $path): self {
    if (is_dir($path)) {
      $path .= '/**/*.{css,scss}';
    }
    $this->neo['stylelint'][$id] = $this->getRelativeRoot() . $path;
    return $this;
  }

  /**
   * Gets the stylelint paths.
   *
   * @return string[]
   *   The paths, keyed by id, each prefixed with the relative root.
   */
  public function getStylelint(): array {
    return $this->neo['stylelint'] ?? [];
  }

}
