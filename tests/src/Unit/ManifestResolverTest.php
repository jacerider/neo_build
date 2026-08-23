<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ActiveTheme;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\neo_build\ManifestResolver;
use Drupal\neo_build\Scope;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Covers the render-time manifest resolution.
 *
 * This is the plan's centre of gravity: a library compiled into one scope and
 * rendered in the other used to miss the lookup silently and ship its raw
 * source file. The whole chain is reachable from a unit test, against a dist
 * root the test writes itself.
 *
 * @see \Drupal\neo_build\ManifestResolver
 */
#[Group('neo_build')]
class ManifestResolverTest extends UnitTestCase {

  /**
   * The app root the resolver reads manifests under.
   */
  protected string $appRoot;

  /**
   * The logger the resolver reports unresolved entrypoints to.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->appRoot = sys_get_temp_dir() . '/neo-build-manifest-' . uniqid('', TRUE);
    mkdir($this->appRoot, 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->deleteRecursive($this->appRoot);
    parent::tearDown();
  }

  /**
   * Criterion: a both-scopes entrypoint resolves against the active scope.
   */
  public function testItResolvesBothScopesEntrypointAgainstTheActiveScope(): void {
    $this->writeManifest('front', ['src/js/shared.ts' => 'js/shared.front.js']);
    $this->writeManifest('back', ['src/js/shared.ts' => 'js/shared.back.js']);

    $resolver = $this->resolver(activeTheme: 'back');

    $this->assertSame(
      '/themes/back/dist/js/shared.back.js',
      $resolver->resolve('src/js/shared.ts', ['front', 'back']),
      'A library in both scopes follows the active scope, as it always has.',
    );
  }

  /**
   * Criterion: a single-scope entrypoint resolves against that scope.
   *
   * This is the defect the plan closes. Rendered under the admin theme, a
   * front-only library used to be looked up in the back manifest, miss, and
   * ship its raw source path.
   */
  public function testItResolvesSingleScopeEntrypointAgainstThatScope(): void {
    $this->writeManifest('front', ['src/js/front-only.ts' => 'js/front-only.abc.js']);
    $this->writeManifest('back', ['src/js/back-only.ts' => 'js/back-only.def.js']);

    $resolver = $this->resolver(activeTheme: 'back');

    $this->assertSame(
      '/themes/front/dist/js/front-only.abc.js',
      $resolver->resolve('src/js/front-only.ts', ['front']),
      'A front-only library must resolve against front, whatever is rendering.',
    );
  }

  /**
   * Criterion: the active scope comes from the theme's machine name.
   *
   * Step 1 of ADR 0002. This is what keeps a site that sets both
   * `default: back` and `admin: back` resolving against back.
   */
  public function testItDerivesTheActiveScopeFromTheThemeMachineName(): void {
    $bothBack = $this->resolver(activeTheme: 'back', adminTheme: 'back', defaultTheme: 'back');
    $this->assertSame(Scope::Back, $bothBack->getActiveScope());

    $front = $this->resolver(activeTheme: 'front', adminTheme: 'back', defaultTheme: 'front');
    $this->assertSame(Scope::Front, $front->getActiveScope());
  }

  /**
   * Criterion: a non-scope admin theme derives back.
   *
   * Step 2 of ADR 0002 — a site whose admin theme is Claro or similar.
   */
  public function testItDerivesBackForNonScopeAdminTheme(): void {
    $resolver = $this->resolver(activeTheme: 'claro', adminTheme: 'claro', defaultTheme: 'front');

    $this->assertSame(Scope::Back, $resolver->getActiveScope());
  }

  /**
   * Criterion: any other active theme derives front.
   *
   * Step 3 of ADR 0002, the fallback.
   */
  public function testItDerivesFrontForAnyOtherActiveTheme(): void {
    $resolver = $this->resolver(activeTheme: 'olivero', adminTheme: 'claro', defaultTheme: 'olivero');

    $this->assertSame(Scope::Front, $resolver->getActiveScope());
  }

  /**
   * Criterion: an unbuilt scope resolves nothing and says nothing.
   *
   * "Not built yet" is the ordinary state of every scope before its first
   * build and of every site during install. A warning per entrypoint there
   * would be noise nobody can act on.
   */
  public function testItResolvesNothingAndLogsNothingWithoutManifest(): void {
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->logger->expects($this->never())->method('warning');

    $resolver = $this->resolver(activeTheme: 'front');

    $this->assertNull($resolver->resolve('src/js/anything.ts', ['front']));
  }

  /**
   * Criterion: a built scope missing the entrypoint warns exactly once.
   */
  public function testItLogsOneWarningForAnUnresolvedEntrypoint(): void {
    $this->writeManifest('front', ['src/js/known.ts' => 'js/known.abc.js']);

    $messages = [];
    $this->logger = $this->createMock(LoggerInterface::class);
    $this->logger->expects($this->once())
      ->method('warning')
      ->willReturnCallback(function (string $message, array $context = []) use (&$messages): void {
        $messages[] = strtr($message, $context);
      });

    $resolver = $this->resolver(activeTheme: 'front');

    $this->assertNull($resolver->resolve('src/js/unknown.ts', ['front']));

    $this->assertCount(1, $messages);
    $this->assertStringContainsString('front', $messages[0], 'The warning names the scope.');
    $this->assertStringContainsString('src/js/unknown.ts', $messages[0], 'The warning names the entrypoint.');
  }

  /**
   * Criterion: each manifest is read at most once per request.
   *
   * Proven from the outside: the file is deleted after the first resolution,
   * and a second resolution still answers. Only a held manifest can do that.
   */
  public function testItReadsEachManifestAtMostOncePerRequest(): void {
    $this->writeManifest('front', [
      'src/js/one.ts' => 'js/one.abc.js',
      'src/js/two.ts' => 'js/two.def.js',
    ]);

    $resolver = $this->resolver(activeTheme: 'front');

    $this->assertSame('/themes/front/dist/js/one.abc.js', $resolver->resolve('src/js/one.ts', ['front']));

    unlink($this->appRoot . '/themes/front/dist/manifest.json');

    $this->assertSame(
      '/themes/front/dist/js/two.def.js',
      $resolver->resolve('src/js/two.ts', ['front']),
      'The manifest was re-read from disk rather than held for the request.',
    );
  }

  /**
   * Criterion: nothing is read until a resolution needs it.
   */
  public function testItReadsNoManifestUntilResolutionNeedsOne(): void {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->expects($this->never())->method('getPath');

    $resolver = new ManifestResolver(
      $this->themeManager('front'),
      $themeExtensionList,
      $this->configFactory('front', 'back'),
      $this->createMock(LoggerInterface::class),
      $this->appRoot,
    );

    $this->assertSame(Scope::Front, $resolver->getActiveScope());
  }

  /**
   * Builds the resolver over the temporary dist root.
   */
  protected function resolver(string $activeTheme, string $adminTheme = 'back', string $defaultTheme = 'front'): ManifestResolver {
    $themeExtensionList = $this->createMock(ThemeExtensionList::class);
    $themeExtensionList->method('getPath')->willReturnCallback(
      static fn (string $name): string => 'themes/' . $name,
    );

    return new ManifestResolver(
      $this->themeManager($activeTheme),
      $themeExtensionList,
      $this->configFactory($defaultTheme, $adminTheme),
      $this->logger ??= $this->createMock(LoggerInterface::class),
      $this->appRoot,
    );
  }

  /**
   * A theme manager reporting the given active theme.
   */
  protected function themeManager(string $activeTheme): ThemeManagerInterface {
    $theme = $this->createMock(ActiveTheme::class);
    $theme->method('getName')->willReturn($activeTheme);

    $themeManager = $this->createMock(ThemeManagerInterface::class);
    $themeManager->method('getActiveTheme')->willReturn($theme);

    return $themeManager;
  }

  /**
   * A config factory answering system.theme.
   */
  protected function configFactory(string $default, string $admin): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (?string $key = NULL): ?string => match ($key) {
        'default' => $default,
        'admin' => $admin,
        default => NULL,
      },
    );

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('system.theme')->willReturn($config);

    return $configFactory;
  }

  /**
   * Writes a scope's manifest under the temporary app root.
   */
  protected function writeManifest(string $scope, array $entries): void {
    $distPath = $this->appRoot . '/themes/' . $scope . '/dist';
    mkdir($distPath, 0775, TRUE);

    $manifest = [];
    foreach ($entries as $source => $file) {
      $manifest[$source] = ['file' => $file, 'src' => $source];
    }

    file_put_contents($distPath . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
  }

  /**
   * Removes a directory tree.
   */
  protected function deleteRecursive(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    $items = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
      $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
  }

}
