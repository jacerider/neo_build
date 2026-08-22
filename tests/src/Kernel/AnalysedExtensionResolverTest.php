<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_build\AnalysedExtensionResolver;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which extensions the generated phpstan.neon analyses.
 *
 * The analysed extensions are every Neo extension, plus every enabled module
 * or theme whose info file declares `package: Neo`, plus modules/custom when
 * that directory exists. Before the package rule, a PHP-only Neo package — one
 * with no Neo libraries — was never analysed even though it is code a site is
 * allowed to commit to; neo_build itself was the first casualty.
 *
 * Every test calls resolve() exactly once. The resolver asks the Neo extension
 * list for the un-scoped list, and until the second-call regression in
 * NeoExtensionList::all() is fixed a second un-scoped call in one process
 * throws. Each test method boots its own kernel, so one call per method is
 * the honest shape.
 */
#[Group('neo_build')]
class AnalysedExtensionResolverTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    // `package: Neo`, and no Neo libraries: analysed only through the
    // package rule.
    'neo_build',
    // A Neo extension: its info file carries `neo: true`.
    'neo_build_test',
    // `package: Neo`, no Neo libraries, enabled here; proves the package rule
    // admits a module.
    'neo_build_stan_module_test',
  ];

  /**
   * Builds a resolver over the container's services and the given app root.
   *
   * The app root is the only thing the resolver reads from the filesystem —
   * the modules/custom existence check — so a vfsStream root lets each test
   * decide whether that directory exists.
   *
   * @param string $appRoot
   *   The app root to resolve modules/custom against.
   *
   * @return \Drupal\neo_build\AnalysedExtensionResolver
   *   The resolver.
   */
  protected function resolver(string $appRoot): AnalysedExtensionResolver {
    return new AnalysedExtensionResolver(
      $this->container->get('extension.list.neo'),
      $this->container->get('extension.list.module'),
      $this->container->get('extension.list.theme'),
      $appRoot,
    );
  }

  /**
   * Every Neo extension is analysed, at its path relative to the app root.
   */
  public function testListsEveryNeoExtension(): void {
    $paths = $this->resolver($this->vfsRoot->url())->resolve();

    $expected = $this->container->get('extension.list.module')->getPath('neo_build_test');
    $this->assertSame($expected, $paths['neo_build_test']);
    // Enabled, not a Neo extension, `package: Core`: not analysed.
    $this->assertArrayNotHasKey('system', $paths);
  }

  /**
   * Enabled `package: Neo` modules and themes are analysed; disabled are not.
   *
   * Neo extensions come first, in the Neo extension list's own order; the
   * extensions the package rule adds follow, sorted by name, so the generated
   * file is stable between runs and the diff a new package produces is one
   * inserted line.
   */
  public function testAddsEnabledPackageNeoExtensionsAndSkipsDisabledOnes(): void {
    $this->container->get('theme_installer')->install(['neo_build_stan_theme_test']);

    $paths = $this->resolver($this->vfsRoot->url())->resolve();

    $modules = $this->container->get('extension.list.module');
    $themes = $this->container->get('extension.list.theme');
    $this->assertSame($modules->getPath('neo_build_stan_module_test'), $paths['neo_build_stan_module_test']);
    $this->assertSame($themes->getPath('neo_build_stan_theme_test'), $paths['neo_build_stan_theme_test']);
    // On disk and discoverable, never enabled.
    $this->assertTrue($modules->exists('neo_build_stan_dormant_test'));
    $this->assertArrayNotHasKey('neo_build_stan_dormant_test', $paths);

    $this->assertSame([
      'neo_build_test',
      'neo_build',
      'neo_build_stan_module_test',
      'neo_build_stan_theme_test',
    ], array_keys($paths));
  }

  /**
   * Modules/custom is appended, last, when the directory exists.
   */
  public function testAppendsCustomModulesWhenTheDirectoryExists(): void {
    vfsStream::newDirectory('modules/custom')->at($this->vfsRoot);

    $paths = $this->resolver($this->vfsRoot->url())->resolve();

    $this->assertSame('modules/custom', $paths['customModules']);
    $this->assertSame('customModules', array_key_last($paths));
  }

  /**
   * Modules/custom is left out when the directory does not exist.
   */
  public function testOmitsCustomModulesWhenTheDirectoryIsAbsent(): void {
    $paths = $this->resolver($this->vfsRoot->url())->resolve();

    $this->assertArrayNotHasKey('customModules', $paths);
    $this->assertNotContains('modules/custom', $paths);
  }

}
