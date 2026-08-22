<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_build\EntityPrintSubmoduleInstaller;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the update that moves the entity_print shim into its submodule.
 *
 * The dev-mode print-asset shim used to live in neo_build itself, swapped in
 * by a service provider whenever entity_print's asset renderer existed. That
 * made neo_build fail PHPStan on every site without entity_print, with errors
 * PHPStan refuses to ignore. The shim now lives in neo_build_entity_print, and
 * neo_build's first update hook enables that submodule wherever entity_print
 * is enabled — and does nothing anywhere else.
 *
 * entity_print is not installed on every site that runs this test, so the
 * enabled branch is exercised by faking the module handler's answer and
 * asserting the installer is asked for the submodule; the absent branch runs
 * the real update hook against the real container and proves the module list
 * did not move.
 */
#[Group('neo_build')]
class EntityPrintSubmoduleInstallerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'neo_build',
  ];

  /**
   * The submodule is installed when entity_print is enabled.
   */
  public function testInstallsTheSubmoduleWhenEntityPrintIsEnabled(): void {
    $handler = $this->createMock(ModuleHandlerInterface::class);
    $handler->method('moduleExists')->willReturnMap([
      ['entity_print', TRUE],
      ['neo_build_entity_print', FALSE],
    ]);
    $installer = $this->createMock(ModuleInstallerInterface::class);
    $installer->expects($this->once())
      ->method('install')
      ->with(['neo_build_entity_print']);

    $this->assertTrue((new EntityPrintSubmoduleInstaller($handler, $installer))->installWhereEntityPrintIsEnabled());
  }

  /**
   * The update hook leaves the module list alone when entity_print is absent.
   */
  public function testLeavesTheModuleListUnchangedWhenEntityPrintIsAbsent(): void {
    $handler = $this->container->get('module_handler');
    $this->assertFalse($handler->moduleExists('entity_print'));
    $before = array_keys($handler->getModuleList());

    $handler->loadInclude('neo_build', 'install');
    $this->assertNull(neo_build_update_11001());

    $this->assertSame($before, array_keys($this->container->get('module_handler')->getModuleList()));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('neo_build_entity_print'));
  }

}
