<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Exception\UnknownExtensionException;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\neo_build\Exception\ManifestCouldNotBeLoadedException;
use Drupal\neo_build\Exception\ManifestNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Resolves a library entrypoint to the built file that should serve it.
 *
 * Owns the whole chain and nothing else: derive the render-time **active
 * scope**, map that scope to its **dist root**, hold one manifest per scope for
 * the duration of the request, resolve an entrypoint to a dist path, and report
 * an entrypoint it could not resolve.
 *
 * The chain used to live inside NeoBuild, which read the *active theme's*
 * manifest whichever library it was resolving. A library compiled into only one
 * scope and rendered in the other missed the lookup, and the miss was silent:
 * the library kept its declared source path and shipped a `.ts` or unbuilt
 * `.css` file to the browser.
 *
 * @see docs/adr/0002-render-time-scope-is-derived-not-persisted.md
 */
final class ManifestResolver {

  /**
   * The manifests held for this request, keyed by scope id.
   *
   * A scope that has been looked up and has no readable manifest is held as
   * NULL, so an unbuilt scope is stat-ed once rather than once per entrypoint.
   *
   * @var array<string, \Drupal\neo_build\NeoManifest|null>
   */
  private array $manifests = [];

  /**
   * Constructs a ManifestResolver.
   *
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager, for the active theme.
   * @param \Drupal\Core\Extension\ThemeExtensionList $themeExtensionList
   *   The theme extension list, for a scope's theme path.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory, for system.theme.
   * @param \Psr\Log\LoggerInterface $logger
   *   The neo_build logger channel.
   * @param string $appRoot
   *   The app root, under which a scope's manifest is read from disk.
   */
  public function __construct(
    private readonly ThemeManagerInterface $themeManager,
    private readonly ThemeExtensionList $themeExtensionList,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly string $appRoot,
  ) {}

  /**
   * The scope this request renders in.
   *
   * Derived per request and never persisted. ADR 0002 records the rule and why
   * a persisted scope-to-dist-root map was rejected; read it before changing
   * the order of these three steps.
   *
   * Step 1 rests on *scope identity* — a scope's id is the machine name of the
   * theme it compiles into — and is what keeps a site that sets both
   * `default: back` and `admin: back` resolving against back. Two of the nine
   * neo_build sites do exactly that, and a rule reading system.theme alone
   * would send every one of their requests to themes/front/dist. Step 2 keeps
   * the answer right for a site whose admin theme is a non-Neo theme such as
   * Claro.
   */
  public function getActiveScope(): Scope {
    $activeTheme = $this->themeManager->getActiveTheme()->getName();

    // 1. The active theme's machine name is a scope id.
    $scope = Scope::tryFrom($activeTheme);
    if ($scope !== NULL) {
      return $scope;
    }

    // 2. The active theme is the site's admin theme.
    $adminTheme = $this->configFactory->get('system.theme')->get('admin');
    if ($adminTheme !== NULL && $adminTheme !== '' && $activeTheme === $adminTheme) {
      return Scope::Back;
    }

    // 3. Anything else.
    return Scope::Front;
  }

  /**
   * Resolves an entrypoint to the built file that should serve it.
   *
   * @param string $entrypoint
   *   The entrypoint as the library declares it, relative to its extension.
   * @param string[] $scopes
   *   The scope ids the library is declared in.
   *
   * @return string|null
   *   The dist path to serve, or NULL when it cannot be resolved.
   */
  public function resolve(string $entrypoint, array $scopes): ?string {
    $scope = $this->scopeFor($scopes);
    if ($scope === NULL) {
      return NULL;
    }

    $manifest = $this->getManifest($scope);
    if ($manifest === NULL) {
      // The scope is not built. That is the ordinary state of every scope
      // before its first build and of every site during install, so it is
      // deliberately silent: a warning here would fire once per entrypoint on
      // a site with no dist/ at all.
      return NULL;
    }

    $distPath = $manifest->getDistPath($entrypoint);
    if ($distPath === NULL) {
      // The scope *is* built and does not carry this entrypoint, which means
      // prepare has not run since the entrypoint was declared. That is the one
      // message worth reading.
      $this->logger->warning('The @scope scope has no built file for the entrypoint @entrypoint. Run a build for that scope: its manifest exists but was written before the entrypoint was declared.', [
        '@scope' => $scope->value,
        '@entrypoint' => $entrypoint,
      ]);
      return NULL;
    }

    return $distPath;
  }

  /**
   * The scope an entrypoint declared in these scopes resolves against.
   *
   * A library declared in both scopes resolves against the active scope —
   * modules default to both scopes, so that is the majority case and it
   * preserves today's behaviour for every entrypoint present in both
   * manifests. A library declared in one scope resolves against *that* scope,
   * which is the defect this closes.
   *
   * @param string[] $scopes
   *   The scope ids the library is declared in.
   */
  private function scopeFor(array $scopes): ?Scope {
    $declared = array_values(array_filter(array_map(
      static fn (string $scope): ?Scope => Scope::tryFrom($scope),
      array_filter($scopes, 'is_string'),
    )));

    if ($declared === []) {
      return NULL;
    }

    $active = $this->getActiveScope();
    if (in_array($active, $declared, TRUE)) {
      return $active;
    }

    return $declared[0];
  }

  /**
   * The manifest for a scope, read at most once per request.
   *
   * Existence and readability are tested *before* NeoManifest is constructed,
   * so "this scope is not built" is an ordinary answer rather than an exception
   * thrown and swallowed once per extension. The exception classes stay for a
   * genuinely corrupt file.
   */
  private function getManifest(Scope $scope): ?NeoManifest {
    if (array_key_exists($scope->value, $this->manifests)) {
      return $this->manifests[$scope->value];
    }

    $this->manifests[$scope->value] = NULL;

    $distPath = $this->getDistPath($scope);
    if ($distPath === NULL) {
      return NULL;
    }

    $manifestPath = $this->appRoot . '/' . $distPath . '/manifest.json';
    if (!is_file($manifestPath) || !is_readable($manifestPath)) {
      return NULL;
    }

    try {
      $this->manifests[$scope->value] = new NeoManifest($manifestPath, $distPath);
    }
    catch (ManifestNotFoundException | ManifestCouldNotBeLoadedException $e) {
      // Unreachable on the ordinary path now that existence and readability are
      // tested above; kept because a genuinely corrupt manifest still throws.
      $this->logger->warning('The @scope scope has a manifest that could not be read: @message', [
        '@scope' => $scope->value,
        '@message' => $e->getMessage(),
      ]);
    }

    return $this->manifests[$scope->value];
  }

  /**
   * The dist root for a scope, relative to the app root.
   *
   * Scope identity again: the scope's id is the machine name of the theme it
   * compiles into. A site that owns its primary file outside that theme gets
   * NULL here and its libraries pass through unrewritten, which is the failure
   * mode ADR 0002 chose over resolving wrongly.
   */
  private function getDistPath(Scope $scope): ?string {
    try {
      return $this->themeExtensionList->getPath($scope->value) . '/dist';
    }
    catch (UnknownExtensionException) {
      return NULL;
    }
  }

}
