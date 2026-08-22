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

  /**
   * A nested extension whose declared dependency is not on disk is excluded.
   *
   * PHPStan refuses to ignore "extends unknown class" and "unknown class"
   * errors, so optional-integration code inside an analysed extension — code
   * that only resolves when some other module is installed — would break the
   * gate on every site without that module. The rule is about resolvability,
   * never a list of files.
   */
  public function testExcludesNestedExtensionWhoseDependencyIsNotOnDisk(): void {
    $resolver = $this->resolver($this->vfsRoot->url());
    $excluded = $resolver->resolveExcluded($resolver->resolve());

    $modules = $this->container->get('extension.list.module');
    $this->assertTrue($modules->exists('neo_build_stan_nested_missing_test'));
    $this->assertSame($modules->getPath('neo_build_stan_nested_missing_test'), $excluded['neo_build_stan_nested_missing_test']);
  }

  /**
   * A nested extension that is on disk but not enabled stays analysed.
   *
   * Enabled-ness is not the question; whether its dependencies resolve is.
   */
  public function testKeepsNestedExtensionThatIsOnDiskButNotEnabled(): void {
    $resolver = $this->resolver($this->vfsRoot->url());
    $excluded = $resolver->resolveExcluded($resolver->resolve());

    $modules = $this->container->get('extension.list.module');
    $this->assertTrue($modules->exists('neo_build_stan_nested_dormant_test'));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('neo_build_stan_nested_dormant_test'));
    $this->assertArrayNotHasKey('neo_build_stan_nested_dormant_test', $excluded);
  }

  /**
   * The entity_print submodule is excluded wherever entity_print is absent.
   *
   * This is the rule applied to the case that motivated it: neo_build's own
   * neo_build_entity_print depends on entity_print, so on a site without
   * entity_print on disk it cannot resolve and drops out of the analysed path.
   */
  public function testExcludesTheEntityPrintSubmoduleWhenEntityPrintIsNotOnDisk(): void {
    $modules = $this->container->get('extension.list.module');
    if ($modules->exists('entity_print')) {
      $this->markTestSkipped('entity_print is on disk here, so the submodule resolves and is analysed rather than excluded.');
    }

    $resolver = $this->resolver($this->vfsRoot->url());
    $excluded = $resolver->resolveExcluded($resolver->resolve());

    $this->assertSame($modules->getPath('neo_build_entity_print'), $excluded['neo_build_entity_print']);
  }

}
