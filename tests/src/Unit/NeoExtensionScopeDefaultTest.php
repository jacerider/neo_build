<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\Core\Extension\Extension;
use Drupal\neo_build\NeoExtension;
use Drupal\neo_build\Scope;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the scope an extension falls into when it declares none.
 *
 * The default is asymmetric on purpose and stays that way: a module's assets
 * are wanted wherever they are used, so it defaults into every scope, while a
 * theme belongs to the one it compiles into. Only the source of the scope list
 * changes here — from a literal pair to the enum.
 */
#[Group('neo_build')]
class NeoExtensionScopeDefaultTest extends UnitTestCase {

  /**
   * Builds a Neo extension of the given type declaring no scope.
   */
  protected function extension(string $type): NeoExtension {
    // Extension checks its info file is really there, so the module's own
    // stands in for one. Only the declared type decides the default.
    $extension = new Extension(dirname(__DIR__, 3), $type, 'neo_build.info.yml');
    $extension->info = ['name' => 'Example', 'type' => $type];
    return new NeoExtension($extension);
  }

  /**
   * A module declaring no scope defaults into every scope.
   */
  public function testModuleDefaultsIntoEveryScope(): void {
    $expected = array_map(static fn (Scope $scope): string => $scope->value, Scope::cases());

    $this->assertSame($expected, $this->extension('module')->getScope());
  }

  /**
   * A theme declaring no scope defaults into front alone.
   */
  public function testThemeDefaultsIntoFrontAlone(): void {
    $this->assertSame([Scope::Front->value], $this->extension('theme')->getScope());
  }

}
