<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the render-time library rewrite end to end, through the alter hook.
 *
 * ManifestResolverTest proves the resolver correct in isolation; this proves
 * NeoBuild calls it correctly. The two are different bugs: a resolver that
 * answers perfectly is worth nothing if the rewrite still asks the active
 * theme's manifest for every library.
 *
 * The scenario is the defect the plan closes. The active theme is the fixture
 * theme, which is also the site's admin theme and is not a scope id, so the
 * active scope derives to `back` by step 2 of ADR 0002. A front-only library
 * rendered there used to be looked up in back's manifest, miss, and ship its
 * raw `.css` and `.ts` source to the browser.
 *
 * @see \Drupal\neo_build\ManifestResolver
 */
#[Group('neo_build')]
class NeoBuildLibraryRewriteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'neo_build',
    'neo_build_test',
    'neo_build_test_back',
  ];

  /**
   * The temporary app root the resolver reads manifests under.
   */
  protected string $distRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->distRoot = sys_get_temp_dir() . '/neo_build_rewrite_' . bin2hex(random_bytes(6));
    parent::setUp();

    $this->installFixtureManifests();

    // The fixture theme is the active theme and the admin theme, and its name
    // is not a scope id — which is exactly ADR 0002's step 2.
    $this->container->get('theme_installer')->install(['neo_build_test_theme']);
    $this->config('system.theme')->set('admin', 'neo_build_test_theme')->save();
    $this->container->get('theme.manager')->setActiveTheme(
      $this->container->get('theme.initialization')->initTheme('neo_build_test_theme'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->distRoot)) {
      $this->container->get('file_system')->deleteRecursive($this->distRoot);
    }
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Point the resolver at the fixture dist root rather than the real site,
    // so the assertions do not depend on this site's own build output.
    $container->getDefinition('neo_build.manifest_resolver')->replaceArgument(4, $this->distRoot);
  }

  /**
   * Criterion: a front-only library resolves against the front manifest.
   */
  public function testFrontOnlyLibraryResolvesAgainstTheFrontScope(): void {
    $libraries = $this->alter('neo_build_test');

    $this->assertSame(
      ['/themes/front/dist/css/neoBuildTestFront.front.css'],
      array_keys($libraries['front']['css']['component'] ?? []),
      'A front-only library rendered under the admin theme must resolve against front.',
    );
    $this->assertSame(
      ['/themes/front/dist/js/neoBuildTestFront.front.js'],
      array_keys($libraries['front']['js'] ?? []),
    );
  }

  /**
   * Criterion: a both-scopes library resolves against the active scope.
   */
  public function testBothScopesLibraryResolvesAgainstTheActiveScope(): void {
    $libraries = $this->alter('neo_build_test');

    $this->assertSame(
      ['/themes/back/dist/css/neoBuildTestShared.back.css'],
      array_keys($libraries['shared']['css']['component'] ?? []),
      'A both-scopes library follows the active scope, which is back here.',
    );
  }

  /**
   * Criterion: nothing is built or rewritten while preventAlter is set.
   */
  public function testNothingIsRewrittenWhilePreventAlterIsSet(): void {
    $neoBuild = $this->container->get('neo_build');
    $neoBuild->preventAlter();

    $declared = $this->declaredLibraries('neo_build_test');
    $libraries = $declared;
    $neoBuild->processLibraries($libraries, 'neo_build_test');

    $this->assertSame($declared, $libraries, 'A suppressed rewrite must leave every path as declared.');
  }

  /**
   * Runs the alter hook over an extension's declared libraries.
   */
  protected function alter(string $extension): array {
    $libraries = $this->declaredLibraries($extension);
    $this->container->get('neo_build')->processLibraries($libraries, $extension);
    return $libraries;
  }

  /**
   * The extension's libraries as declared, before any rewrite.
   *
   * Read from the YAML rather than through buildByExtension(), which fires
   * hook_library_info_alter itself — the rewrite under test would then have run
   * once already, and asserting on it a second time would prove nothing.
   */
  protected function declaredLibraries(string $extension): array {
    $path = $this->container->get('extension.list.module')->getPath($extension);
    return Yaml::parseFile($path . '/' . $extension . '.libraries.yml');
  }

  /**
   * Copies the committed fixture manifests into the temporary dist root.
   *
   * The committed files carry a `%extension%` placeholder rather than a
   * hard-coded module path: where Composer installs neo_build is a per-site
   * fact, and a baked-in prefix would make these fixtures silently miss.
   */
  protected function installFixtureManifests(): void {
    $extensionPath = $this->container->get('extension.list.module')->getPath('neo_build_test');
    $source = $this->container->get('extension.list.module')->getPath('neo_build') . '/modules/neo_build_test/fixtures/dist-root';

    foreach (['front', 'back'] as $scope) {
      $target = $this->distRoot . '/themes/' . $scope . '/dist';
      mkdir($target, 0775, TRUE);
      file_put_contents(
        $target . '/manifest.json',
        str_replace('%extension%', $extensionPath, file_get_contents($source . '/themes/' . $scope . '/dist/manifest.json')),
      );
    }
  }

}
