<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;

/**
 * Enables neo_build_entity_print on sites where entity_print is enabled.
 *
 * The decision behind neo_build_update_11001(), kept out of the hook so a
 * kernel test can fake the "entity_print is enabled" answer on a site that
 * does not have entity_print at all.
 */
final class EntityPrintSubmoduleInstaller {

  /**
   * The submodule carrying the dev-mode print-asset shim.
   */
  public const SUBMODULE = 'neo_build_entity_print';

  /**
   * The module the shim integrates with.
   */
  public const ENTITY_PRINT = 'entity_print';

  /**
   * Constructs an EntityPrintSubmoduleInstaller.
   *
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Extension\ModuleInstallerInterface $moduleInstaller
   *   The module installer.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ModuleInstallerInterface $moduleInstaller,
  ) {}

  /**
   * Installs the submodule when entity_print is enabled and it is not yet.
   *
   * @return bool
   *   TRUE when this call installed the submodule; FALSE when entity_print is
   *   not enabled or the submodule already is.
   */
  public function installWhereEntityPrintIsEnabled(): bool {
    if (!$this->moduleHandler->moduleExists(self::ENTITY_PRINT)) {
      return FALSE;
    }
    if ($this->moduleHandler->moduleExists(self::SUBMODULE)) {
      return FALSE;
    }
    $this->moduleInstaller->install([self::SUBMODULE]);
    return TRUE;
  }

}
