<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheCollector;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\neo_build\Event\NeoBuildEvent;
use Drupal\neo_build\Generator\Artifact;
use Drupal\neo_build\Generator\NeoJsonGenerator;
use Drupal\neo_build\Generator\PhpstanGenerator;
use Drupal\neo_build\Generator\TailwindCssGenerator;
use Drupal\neo_build\Generator\TailwindStylesheet;
use Drupal\neo_build\Generator\TsConfigGenerator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Prepares a scope: turns the site's Neo extensions into the build's inputs.
 *
 * One operation — prepare a named scope, return a prepare result. In order:
 * reject an unknown scope; reset the module and theme extension information;
 * record the scope in Neo state; clear cached library definitions; suspend the
 * render-time library rewrite; build the collection from settings, state and
 * the project root; add every scoped Neo extension's contributions; dispatch
 * the build event with the collection and the scoped extensions; resume the
 * rewrite and clear library definitions again; run the generators and write
 * their artifacts; invalidate the build cache tag.
 *
 * The preparer does not print. The result carries the artifacts written and
 * the notices gathered — extension added, missing entrypoint skipped, no
 * primary file — and the neo:build command prints them.
 */
class Preparer {

  /**
   * The state key under which the prepared scope is recorded.
   *
   * Aliases NeoBuild's constant rather than repeating the string: the key is
   * spelled once, and the pair cannot drift apart.
   */
  public const SCOPE_STATE_KEY = NeoBuild::SCOPE_STATE_KEY;

  /**
   * The state key carrying the DEV flag.
   */
  public const DEV_STATE_KEY = NeoBuild::DEV_STATE_KEY;

  /**
   * The cache tag a prepare invalidates.
   */
  public const BUILD_CACHE_TAG = 'neo_build:build';

  /**
   * The cache tag a prepare invalidates additionally while in DEV mode.
   */
  public const DEV_BUILD_CACHE_TAG = 'neo_build:build:dev';

  /**
   * Constructs a Preparer.
   *
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   * @param \Drupal\Core\Asset\LibraryDiscoveryInterface $libraryDiscovery
   *   The library discovery.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher.
   * @param \Drupal\neo_build\NeoExtensionList $neoExtensionList
   *   The Neo extension list.
   * @param \Drupal\neo_build\NeoBuild $neoBuild
   *   The build service, through which the render-time library rewrite is
   *   suspended for the duration of a prepare.
   * @param \Drupal\neo_build\AnalysedExtensionResolver $analysedExtensionResolver
   *   The analysed-extension resolver.
   * @param \Drupal\neo_build\ProjectRootInterface $projectRoot
   *   The project root.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator.
   * @param string $appRoot
   *   The app root, against which extension paths are checked on disk.
   */
  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly StateInterface $state,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly NeoExtensionList $neoExtensionList,
    private readonly NeoBuild $neoBuild,
    private readonly AnalysedExtensionResolver $analysedExtensionResolver,
    private readonly ProjectRootInterface $projectRoot,
    private readonly FileSystemInterface $fileSystem,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly string $appRoot,
  ) {}

  /**
   * Prepares a scope.
   *
   * @param \Drupal\neo_build\Scope|string $scope
   *   The scope, as a case or as its id. Drush hands the `neo:build <scope>`
   *   argument through as a string, so both forms are accepted and normalised
   *   here rather than at every call site.
   *
   * @return \Drupal\neo_build\PrepareResult
   *   The artifacts written and the notices gathered.
   *
   * @throws \InvalidArgumentException
   *   When the scope does not exist.
   */
  public function prepare(Scope|string $scope): PrepareResult {
    $case = $scope instanceof Scope ? $scope : Scope::tryFrom($scope);
    if ($case === NULL) {
      throw new \InvalidArgumentException(sprintf("Scope '%s' does not exist.", $scope));
    }
    $scope = $case->value;
    $result = new PrepareResult($scope, $case->label());

    // Clear extension information.
    $this->moduleExtensionList->reset();
    $this->themeHandler->refreshInfo();

    $this->state->set(self::SCOPE_STATE_KEY, $scope);

    // The absolute path of the project checkout on the host.
    $root = $this->projectRoot->getRoot();
    // Example: web/.
    $docRoot = $this->projectRoot->getDocRoot();

    $this->clearLibraryDefinitions();
    $this->neoBuild->preventAlter();

    $collection = new NeoBuildCollection(
      NeoBuild::getNeoSetting('port'),
      (bool) $this->state->get(self::DEV_STATE_KEY, FALSE),
      $root,
      $docRoot,
      $this->moduleHandler->getModule('neo_build')->getPath(),
    );
    $collection->setScope($scope);

    // The analysed extensions: every Neo extension, every enabled
    // `package: Neo` extension, modules/custom — and the nested ones the
    // exclusion rule keeps out of the analysed paths.
    $analysedExtensions = $this->analysedExtensionResolver->resolve();
    $excludedExtensions = $this->analysedExtensionResolver->resolveExcluded($analysedExtensions);

    $scopedExtensions = $this->neoExtensionList->all([$scope]);
    foreach ($scopedExtensions as $extension) {
      $this->addExtension($extension, $collection, $result);
    }

    $event = new NeoBuildEvent($collection, $scopedExtensions);
    $this->eventDispatcher->dispatch($event, NeoBuildEvent::EVENT_NAME);

    $this->neoBuild->preventAlter(FALSE);
    $this->clearLibraryDefinitions();

    // One generator per artifact, each read-only over the collection, so the
    // order they run in cannot change what any of them writes.
    $generators = [
      new NeoJsonGenerator(),
      new TsConfigGenerator(),
      new PhpstanGenerator($analysedExtensions, PhpstanGenerator::extensionInstallerInstalled(), $excludedExtensions),
      new TailwindCssGenerator(),
    ];
    foreach ($generators as $generator) {
      $artifact = $generator->generate($collection);
      if ($artifact === NULL) {
        if ($generator instanceof TailwindCssGenerator) {
          $result->addNotice(new PrepareNotice(PrepareNotice::MISSING_PRIMARY_FILE, TailwindCssGenerator::MISSING_PRIMARY_FILE_NOTICE));
        }
        continue;
      }
      $this->write($artifact);
      $result->addArtifact($artifact->getDestination());
    }

    $this->cacheTagsInvalidator->invalidateTags([self::BUILD_CACHE_TAG]);

    return $result;
  }

  /**
   * Adds a scoped Neo extension's contributions to the collection.
   *
   * Tailwind imports and sources, the four info-file Tailwind sections by
   * name, a notice for every retired section the extension still declares,
   * then for every library the Vite entrypoints (with their existence
   * checks), the TS includes and the stylelint globs. Paths stored in the
   * collection stay relative to the app root; every check against the disk is
   * absolute, so the result does not depend on the working directory.
   *
   * @param \Drupal\neo_build\NeoExtension $extension
   *   The extension.
   * @param \Drupal\neo_build\NeoBuildCollection $collection
   *   The collection.
   * @param \Drupal\neo_build\PrepareResult $result
   *   The result, for notices.
   */
  protected function addExtension(NeoExtension $extension, NeoBuildCollection $collection, PrepareResult $result): void {
    $name = $extension->getName();
    $path = $extension->getPath();
    $result->addNotice(new PrepareNotice(PrepareNotice::EXTENSION_ADDED, sprintf('Extension added: %s (%s)', $extension->getLabel(), $name)));

    foreach ($extension->getLibraries() as $libraryId => $library) {
      if ($library->isImport()) {
        $collection->addTailwindImport($name . ':' . $libraryId, (string) $library->getCssPath());
      }
    }
    $collection->addTailwindSource($name . ':Files', $path . '/src/**/*.{js,ts,jsx,tsx,php}');
    $collection->addTailwindSource($name . ':Module', $path . '/*.{module,inc,theme}');
    if (is_dir($this->appRoot . '/' . $path . '/templates')) {
      $collection->addTailwindSource($name . ':Twig', $path . '/templates/**/*.twig');
    }
    $tailwind = $extension->getTailwindInfo();
    foreach ($tailwind as $key => $data) {
      $this->assertFlatTailwindData($name, $key, $data);
    }
    // Four sections, four calls. Building the method name and testing it with
    // method_exists() meant that removing a collection method turned a
    // condition false and the section stopped arriving in silence — which is
    // exactly how `base` outlived the layer it fed. Named calls make the next
    // such removal a call site that cannot compile.
    $collection->addTailwindTheme($tailwind['theme']);
    $collection->addTailwindComponents($tailwind['components']);
    $collection->addTailwindUtilities($tailwind['utilities']);
    $collection->addTailwindVariants($tailwind['variants']);

    foreach ($extension->getRetiredTailwindSections() as $retired) {
      $result->addNotice(new PrepareNotice(PrepareNotice::RETIRED_TAILWIND_SECTION, sprintf(
        'Retired Tailwind section dropped: "%s" in %s. Nothing reads it, and the build continues without it. Move custom properties into the extension\'s `neo: theme:` section and rules into `neo: components:`.',
        $retired,
        $name,
      )));
    }

    foreach ($extension->getLibraries() as $library) {
      $this->addViteLibFromLibrary($library, $collection, $result);
      $this->addStylelintFromLibrary($library, $collection);
    }
  }

  /**
   * Asserts one extension's info-file Tailwind rules are flat, naming it.
   *
   * The same rule the stylesheet enforces, applied one step earlier so the
   * refusal can say whose file to open. `TailwindStylesheet::addRule()` is the
   * last gate and stays that way — it catches a build event subscriber, which
   * no extension name describes — but it runs from
   * `TailwindCssGenerator::generate()`, by which point every extension has
   * been read into the collection and gone out of scope. Here the extension
   * is still in hand.
   *
   * Only `components` and `utilities` carry rules. `theme` is custom
   * properties and `variants` is a selector list; neither is a
   * selector-to-properties map, so neither is checked here.
   *
   * @param string $extension
   *   The extension whose info file declared the data.
   * @param string $key
   *   The `neo:` key the data came from.
   * @param array $data
   *   The data: for a rule-carrying key, selectors to properties.
   *
   * @throws \InvalidArgumentException
   *   If a rule is not flat, with the extension named in front of the
   *   stylesheet's own message.
   */
  protected function assertFlatTailwindData(string $extension, string $key, array $data): void {
    if ($key !== 'components' && $key !== 'utilities') {
      return;
    }

    foreach ($data as $selector => $properties) {
      if (!is_array($properties)) {
        continue;
      }

      try {
        TailwindStylesheet::assertFlatDeclarations((string) $selector, $properties);
      }
      catch (\InvalidArgumentException $e) {
        throw new \InvalidArgumentException(sprintf(
          'The extension "%s" declares Tailwind data that is not flat, under "neo: %s:". %s',
          $extension,
          $key,
          $e->getMessage(),
        ), $e->getCode(), $e);
      }
    }
  }

  /**
   * Adds a library's entrypoints to the collection.
   *
   * A missing entrypoint is skipped with a notice. The CSS entrypoint that
   * imports "tailwindcss" is the scope's primary file. A TS entrypoint also
   * brings its extension's typings directory into the TS includes.
   *
   * @param \Drupal\neo_build\NeoLibrary $library
   *   The library.
   * @param \Drupal\neo_build\NeoBuildCollection $collection
   *   The collection.
   * @param \Drupal\neo_build\PrepareResult $result
   *   The result, for notices.
   */
  protected function addViteLibFromLibrary(NeoLibrary $library, NeoBuildCollection $collection, PrepareResult $result): void {
    if ($path = $library->getCssPath()) {
      if (!file_exists($this->appRoot . '/' . $path)) {
        $result->addNotice(new PrepareNotice(PrepareNotice::MISSING_ENTRYPOINT, sprintf('Missing CSS file skipped: %s (%s)', $path, $library->id())));
      }
      // Libraries set for import do not need to be handled by Vite as they
      // will be imported into the main CSS file.
      elseif (!$library->isImport()) {
        if ($this->isTailwindBaseCss($this->appRoot . '/' . $path)) {
          $collection->setPrimaryRoot($library->getExtension()->getPath());
          $collection->setPrimaryFile($path);
        }
        $collection->addViteLib($library->id() . ':Css', $path);
      }
    }
    if ($path = $library->getJsPath()) {
      if (!file_exists($this->appRoot . '/' . $path)) {
        $result->addNotice(new PrepareNotice(PrepareNotice::MISSING_ENTRYPOINT, sprintf('Missing JS file skipped: %s (%s)', $path, $library->id())));
        return;
      }
      $collection->addViteLib($library->id() . ':Js', $path);
      if (substr($path, -3) === '.ts') {
        $collection->addTsInclude($path);
        $typingPath = $library->getExtension()->getPath() . '/src/js/typings';
        if (is_dir($this->appRoot . '/' . $typingPath)) {
          $collection->addTsInclude($typingPath . '/*.d.ts');
        }
      }
    }
  }

  /**
   * Adds a library's stylelint path to the collection.
   *
   * A theme's stylesheets are linted by directory; a module's by file. A
   * directory is widened to a glob here — the collection stores what it is
   * handed and does not look at the disk.
   *
   * @param \Drupal\neo_build\NeoLibrary $library
   *   The library.
   * @param \Drupal\neo_build\NeoBuildCollection $collection
   *   The collection.
   */
  protected function addStylelintFromLibrary(NeoLibrary $library, NeoBuildCollection $collection): void {
    if ($path = $library->getCssPath()) {
      if ($library->getType() === 'theme') {
        $id = $library->getExtension()->getName();
        $path = dirname($path);
      }
      else {
        $id = $library->id();
      }
      if (is_dir($this->appRoot . '/' . $path)) {
        $path .= '/**/*.{css,scss}';
      }
      $collection->addStylelint($id, $path);
    }
  }

  /**
   * Tells whether a CSS file is a primary file: one that imports tailwindcss.
   *
   * @param string $absolutePath
   *   The absolute path to the CSS file.
   *
   * @return bool
   *   TRUE when the file imports "tailwindcss".
   */
  protected function isTailwindBaseCss(string $absolutePath): bool {
    $data = (string) file_get_contents($absolutePath);
    return (bool) preg_match('/^@import\s+["\']tailwindcss["\'](.*);?$/m', $data);
  }

  /**
   * Writes an artifact, creating its directory when it is missing.
   *
   * @param \Drupal\neo_build\Generator\Artifact $artifact
   *   The artifact.
   */
  protected function write(Artifact $artifact): void {
    $directory = dirname($artifact->getDestination());
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->saveData($artifact->getContent(), $artifact->getDestination(), FileExists::Replace);
  }

  /**
   * Clears the cached library definitions.
   *
   * Prepare switches the render-time library rewrite off and on around the
   * build event, so definitions cached on either side of that switch are wrong
   * for the other; they are cleared before and after.
   *
   * On Drupal 11.1+ `library.discovery` is LibraryDiscoveryCollector, a
   * CacheCollector: clear() empties its in-process storage and invalidates the
   * persisted item, and nothing is persisted at shutdown. On 10.3 it is the
   * thin LibraryDiscovery wrapper, whose collector is out of reach; there the
   * persisted item is tagged `library_info`, so invalidating that tag drops it
   * — what the wrapper's clearCachedDefinitions() did, minus the call to a
   * method 11.1 deprecates. The one thing the tag path does not do is reset
   * the wrapper's in-process copy, which only matters if a build-event
   * subscriber reads definitions through `library.discovery` mid-prepare; no
   * Neo subscriber does, and the process ends with the command.
   */
  protected function clearLibraryDefinitions(): void {
    if ($this->libraryDiscovery instanceof CacheCollector) {
      $this->libraryDiscovery->clear();
      return;
    }
    Cache::invalidateTags(['library_info']);
  }

}
