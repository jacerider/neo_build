<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Commands\DrushCommands;
use Drupal\neo_build\Scope;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins what the build CLI reads out of the scopes command.
 *
 * `neo-scopes --format=json` is the only PHP/Node contract in the package: the
 * build CLI parses it for the deploy loop, the interactive prompt and argument
 * validation, so its shape is a published interface with no other test on it.
 *
 * It is also where the admin-theme pruning lived — a block that dropped the
 * front scope whenever Drush's negotiated active theme happened to equal the
 * admin theme, making that scope unreachable from `npm run deploy` while
 * `drush neo front` prepared it happily. The command now reports the enum, so
 * these tests construct it without its constructor: nothing it injects can
 * reach this behaviour any more, and a test that had to mock a theme manager
 * to prove that would be asserting the mock.
 */
#[Group('neo_build')]
class DrushScopesTest extends UnitTestCase {

  /**
   * The command object, built without touching its dependencies.
   */
  protected function commands(): DrushCommands {
    return (new \ReflectionClass(DrushCommands::class))->newInstanceWithoutConstructor();
  }

  /**
   * Every scope is reported, whatever any theme is set to.
   */
  public function testReportsEveryScope(): void {
    $rows = $this->commands()->neoBuildScopes()->getArrayCopy();

    $this->assertSame(['front', 'back'], array_keys($rows));
  }

  /**
   * Each row carries the keys the build CLI parses, keyed by scope id.
   */
  public function testEachRowCarriesTheKeysTheBuildCliParses(): void {
    $rows = $this->commands()->neoBuildScopes()->getArrayCopy();

    foreach ($rows as $id => $row) {
      $scope = Scope::from($id);
      $this->assertSame(['id', 'label', 'description'], array_keys($row));
      $this->assertSame($id, $row['id']);
      $this->assertSame($scope->label(), $row['label']);
      $this->assertSame($scope->description(), $row['description']);
    }
  }

  /**
   * The template command offers one theme per scope, named from the enum.
   */
  public function testTheTemplateCommandOffersOneThemePerScope(): void {
    $expected = [];
    foreach (Scope::cases() as $scope) {
      $expected[$scope->themeName()] = $scope->label();
    }

    $method = new \ReflectionMethod(DrushCommands::class, 'templateThemeChoices');
    $method->setAccessible(TRUE);

    $this->assertSame($expected, $method->invoke($this->commands()));
  }

  /**
   * Neither the theme manager nor the scope manager is a dependency any more.
   */
  public function testTheCommandTakesNeitherTheThemeManagerNorTheScopeManager(): void {
    $constructor = (new \ReflectionClass(DrushCommands::class))->getConstructor();
    $this->assertNotNull($constructor);

    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $type = $parameter->getType();
      $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : '';
    }
    $this->assertNotContains('Drupal\Core\Theme\ThemeManagerInterface', $types);
    $this->assertNotContains('Drupal\Component\Plugin\PluginManagerInterface', $types);
    // The compiled-versions stamp still needs it.
    $this->assertContains('Drupal\Core\Config\ConfigFactoryInterface', $types);

    $arguments = Yaml::parseFile(__DIR__ . '/../../../drush.services.yml')['services']['neo']['arguments'];
    $this->assertNotContains('@theme.manager', $arguments);
    $this->assertNotContains('@plugin.manager.neo_build_scope', $arguments);
    $this->assertContains('@config.factory', $arguments);
  }

}
