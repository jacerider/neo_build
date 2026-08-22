<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_build\Scope;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the inline libraries the module builds, one per scope.
 *
 * `hook_library_info_build()` is how each scope's generated stylesheet reaches
 * a page: `neo_build/front` and `neo_build/back` are attached by name across
 * the Neo themes, so the set and the naming are an interface. Nothing asserted
 * either before this test, which meant a scope quietly building no library at
 * all would have looked exactly like a scope whose CSS was empty.
 */
#[Group('neo_build')]
class NeoInlineLibrariesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'neo_build',
  ];

  /**
   * One library per scope, named for it, and nothing else.
   */
  public function testBuildsExactlyOneLibraryPerScope(): void {
    $libraries = $this->container->get('library.discovery')->getLibrariesByExtension('neo_build');

    $expected = array_map(static fn (Scope $scope): string => $scope->value, Scope::cases());
    $this->assertEqualsCanonicalizing($expected, array_keys($libraries));
  }

  /**
   * Each library points at its own scope's generated stylesheet.
   */
  public function testEachLibraryPointsAtItsScopesStylesheet(): void {
    $libraries = $this->container->get('library.discovery')->getLibrariesByExtension('neo_build');

    foreach (Scope::cases() as $scope) {
      $this->assertArrayHasKey($scope->value, $libraries);
      $css = $libraries[$scope->value]['css'];
      $this->assertCount(1, $css);
      $this->assertSame('public://neo-build/' . $scope->value . '.css', $css[0]['data']);
    }
  }

}
