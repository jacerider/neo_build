<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins that the scope plugin type is gone and stays gone.
 *
 * It promised an extension point — ship a `*.neo_build_scopes.yml`, or
 * implement `hook_neo_build_scope_info_alter()`, and the build gains a scope —
 * that nothing on any site ever took up, and that could not have worked
 * on its own anyway: a scope also needs a theme, a base theme, a primary file,
 * an inline library and a settings entry. The `Scope` enum states the set
 * instead, and the manager, its YAML, its service and its alter hook were
 * removed outright with no deprecation cycle.
 *
 * Reintroducing any of them would restore the promise without restoring
 * anything that makes it true, which is what this test is here to stop.
 */
#[Group('neo_build')]
class ScopePluginTypeRemovedTest extends UnitTestCase {

  /**
   * The package root.
   */
  protected function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * The manager class and the scopes YAML are gone.
   */
  public function testTheManagerAndItsYamlAreGone(): void {
    $this->assertFileDoesNotExist($this->packageRoot() . '/src/ScopePluginManager.php');
    $this->assertFileDoesNotExist($this->packageRoot() . '/neo_build.neo_build_scopes.yml');
    $this->assertFalse(class_exists('Drupal\neo_build\ScopePluginManager'));
  }

  /**
   * The service is defined in neither service file, and nothing references it.
   */
  public function testTheServiceIsDefinedNowhereAndReferencedNowhere(): void {
    foreach (['neo_build.services.yml', 'drush.services.yml'] as $file) {
      $services = Yaml::parseFile($this->packageRoot() . '/' . $file)['services'];
      $this->assertArrayNotHasKey('plugin.manager.neo_build_scope', $services, $file);

      foreach ($services as $id => $definition) {
        $this->assertNotContains(
          '@plugin.manager.neo_build_scope',
          $definition['arguments'] ?? [],
          sprintf('Service %s in %s still takes the scope plugin manager.', $id, $file),
        );
      }
    }
  }

}
