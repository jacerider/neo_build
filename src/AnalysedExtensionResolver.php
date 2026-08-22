<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeExtensionList;

/**
 * Resolves the extensions the generated phpstan.neon analyses.
 *
 * The analysed extensions are:
 * - every Neo extension — a module or theme with a `neo` key in its info file
 *   or a Neo library — as before;
 * - every enabled module or theme whose info file declares `package: Neo`
 *   (exact match), so the PHP-only Neo packages with no Neo libraries —
 *   neo_build itself, neo_settings, neo_twig, neo_font, neo_config_file … —
 *   are analysed too. A disabled one is not;
 * - modules/custom, when that directory exists under the app root.
 *
 * Paths are relative to the app root, as the extension lists report them. The
 * order is stable: Neo extensions first, in the Neo extension list's own
 * order; then the extensions the package rule adds, sorted by name; then
 * modules/custom. Nothing here touches the filesystem except the
 * modules/custom existence check.
 */
class AnalysedExtensionResolver {

  /**
   * The key under which modules/custom is reported.
   */
  public const CUSTOM_MODULES = 'customModules';

  /**
   * Constructs an AnalysedExtensionResolver.
   *
   * @param \Drupal\neo_build\NeoExtensionList $neoExtensionList
   *   The Neo extension list.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list.
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeExtensionList
   *   The theme extension list.
   * @param string $appRoot
   *   The app root, against which modules/custom is checked.
   */
  public function __construct(
    private readonly NeoExtensionList $neoExtensionList,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly string $appRoot,
  ) {}

  /**
   * Resolves the analysed extensions.
   *
   * @return array<string, string>
   *   Extension paths relative to the app root, keyed by extension name, in
   *   emission order. When modules/custom exists it is last, under
   *   self::CUSTOM_MODULES.
   */
  public function resolve(): array {
    $paths = [];
    foreach ($this->neoExtensionList->all() as $name => $extension) {
      $paths[$name] = $extension->getPath();
    }

    $packaged = [];
    $lists = [
      $this->moduleExtensionList->getList(),
      $this->themeExtensionList->getList(),
    ];
    foreach ($lists as $extensions) {
      foreach ($extensions as $name => $extension) {
        if (isset($paths[$name]) || empty($extension->status)) {
          continue;
        }
        if (($extension->info['package'] ?? NULL) === 'Neo') {
          $packaged[$name] = $extension->getPath();
        }
      }
    }
    ksort($packaged, SORT_STRING);
    $paths += $packaged;

    if (is_dir($this->appRoot . '/modules/custom')) {
      $paths[self::CUSTOM_MODULES] = 'modules/custom';
    }

    return $paths;
  }

}
