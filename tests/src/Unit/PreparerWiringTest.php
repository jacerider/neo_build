<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Preparer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins that the preparer no longer depends on the scope plugin manager.
 *
 * The dependency has to be gone from both halves or the deletion of the plugin
 * type is a container-compilation failure waiting for the next cache rebuild:
 * from the constructor, and from the service definition that fills it. A class
 * that stopped using an injected service while its definition still passes one
 * looks migrated and is not.
 */
#[Group('neo_build')]
class PreparerWiringTest extends UnitTestCase {

  /**
   * The scope plugin manager's service id.
   */
  protected const SCOPE_MANAGER_SERVICE = '@plugin.manager.neo_build_scope';

  /**
   * The constructor takes no plugin manager.
   */
  public function testTheConstructorTakesNoPluginManager(): void {
    $constructor = (new \ReflectionClass(Preparer::class))->getConstructor();
    $this->assertNotNull($constructor);

    foreach ($constructor->getParameters() as $parameter) {
      $type = $parameter->getType();
      $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
      $this->assertStringNotContainsStringIgnoringCase(
        'PluginManager',
        $name,
        sprintf('Parameter $%s still takes a plugin manager (%s).', $parameter->getName(), $name),
      );
      $this->assertStringNotContainsStringIgnoringCase(
        'scopeManager',
        $parameter->getName(),
        'The scope plugin manager is still a constructor parameter.',
      );
    }
  }

  /**
   * The service definition passes no scope plugin manager.
   */
  public function testTheServiceDefinitionPassesNoScopeManager(): void {
    $services = Yaml::parseFile(__DIR__ . '/../../../neo_build.services.yml')['services'];

    $this->assertArrayHasKey('neo_build.preparer', $services);
    $this->assertNotContains(self::SCOPE_MANAGER_SERVICE, $services['neo_build.preparer']['arguments']);
  }

}
