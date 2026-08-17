<?php

/**
 * @file
 * Test site setup for Neo Nightwatch tests.
 *
 * Drupal's test-site installer takes a --setup-file and instantiates the last
 * class the file declares, with no constructor arguments — so there is no way
 * to parameterise it directly. The accompanying drupalInstallNeo() command
 * writes a JSON payload to a fixed path and this reads it back, which keeps one
 * shared setup file serving every Neo package instead of each one shipping a
 * near-identical copy.
 *
 * A payload file rather than an environment variable on purpose: when
 * DRUPAL_TEST_WEBSERVER_USER is set, Nightwatch runs the installer through
 * `sudo`, which drops the environment. That would silently install the wrong
 * module set rather than fail, which is the worst possible outcome for a test
 * fixture.
 */

declare(strict_types=1);

use Drupal\TestSite\TestSetupInterface;

/**
 * Installs the themes and modules a Neo test site needs.
 */
class NeoTestSiteSetup implements TestSetupInterface {

  /**
   * Where drupalInstallNeo() writes the install payload.
   */
  const PAYLOAD = '/tmp/neo-nightwatch-install.json';

  /**
   * {@inheritdoc}
   */
  public function setup() {
    $payload = $this->payload();

    $moduleInstaller = \Drupal::service('module_installer');

    if (!empty($payload['modules'])) {
      // Installing a module imports its config/install, so shipped settings and
      // preset variations arrive with it and need no separate step.
      $moduleInstaller->install($payload['modules']);
    }

    // Neo compiles its assets per scope into the theme that owns the scope
    // (front/back), so a site left on the install profile's default theme would
    // have no Neo CSS or JS at all and every asset assertion would fail for a
    // reason that has nothing to do with the code under test.
    $themes = array_values(array_filter([
      $payload['theme'] ?? NULL,
      $payload['adminTheme'] ?? NULL,
    ]));
    if ($themes) {
      // ThemeInstaller refuses a theme whose module dependencies are missing,
      // and does not install them itself. Neo themes depend on several Neo
      // modules that the requested module list has no reason to mention, so
      // they are resolved from the theme's own info rather than made every
      // caller's problem.
      $moduleInstaller->install(array_unique(array_merge(
        $this->themeModuleDependencies($themes),
        $this->themeConfigProviders($themes)
      )));
      \Drupal::service('theme_installer')->install($themes);
    }

    $config = \Drupal::configFactory()->getEditable('system.theme');
    if (!empty($payload['theme'])) {
      $config->set('default', $payload['theme']);
    }
    if (!empty($payload['adminTheme'])) {
      $config->set('admin', $payload['adminTheme']);
    }
    $config->save();
  }

  /**
   * Collects the module dependencies declared by a set of themes.
   *
   * Walks each theme's base-theme chain. A Neo site theme typically declares no
   * module dependencies itself — front inherits from neo_front, which inherits
   * from neo_base, and it is neo_base that requires neo, neo_twig, neo_icon and
   * neo_font. Reading only the named theme finds nothing and the install fails
   * on unmet dependencies.
   *
   * @param array $themes
   *   Theme machine names.
   *
   * @return array
   *   Module machine names, which may include modules already installed —
   *   module_installer treats those as a no-op.
   */
  protected function themeModuleDependencies(array $themes): array {
    // getExtensionInfo() resolves against INSTALLED extensions, and on a fresh
    // test site none of these themes are installed yet — that is the whole
    // point. getAllAvailableInfo() reads what is on disk instead.
    $available = \Drupal::service('extension.list.theme')->getAllAvailableInfo();
    $modules = [];
    $seen = [];
    $queue = $themes;

    while ($queue) {
      $theme = array_shift($queue);
      if (isset($seen[$theme]) || !isset($available[$theme])) {
        continue;
      }
      $seen[$theme] = TRUE;
      $info = $available[$theme];
      foreach ($info['dependencies'] ?? [] as $dependency) {
        // Dependencies are written as "project:module"; the project prefix is
        // not part of the machine name.
        $parts = explode(':', $dependency);
        $modules[] = end($parts);
      }
      if (!empty($info['base theme'])) {
        $queue[] = $info['base theme'];
      }
    }

    // Drupal folds a theme's base theme into its dependencies list, so the
    // names collected above are a mix of modules and themes. Feeding a theme
    // name to the module installer is a hard error, so keep only the ones that
    // are actually modules — the themes are handled by the theme installer.
    $moduleList = \Drupal::service('extension.list.module');
    $modules = array_filter(array_unique($modules), function ($name) use ($moduleList) {
      return $moduleList->exists($name);
    });

    return array_values($modules);
  }

  /**
   * Finds the modules that provide a theme's shipped config.
   *
   * Installing a theme also imports its config/install, and Drupal refuses if
   * the module that owns a config entity type is absent. Neo site themes ship
   * block placements, so `block` has to be present — but nothing in the theme's
   * declared dependencies says so, because the requirement comes from the
   * config objects rather than from the theme itself.
   *
   * A config object's name is prefixed with its provider, so the provider is
   * simply the first segment of the filename: block.block.branding.yml is
   * provided by `block`.
   *
   * @param array $themes
   *   Theme machine names.
   *
   * @return array
   *   Module machine names.
   */
  protected function themeConfigProviders(array $themes): array {
    // getList() is the available-on-disk listing, unlike the installed-only
    // accessors, and it carries the paths that getAllAvailableInfo() lacks.
    $extensions = \Drupal::service('extension.list.theme')->getList();
    $moduleList = \Drupal::service('extension.list.module');
    $providers = [];

    foreach ($themes as $theme) {
      if (!isset($extensions[$theme])) {
        continue;
      }
      $base = DRUPAL_ROOT . '/' . $extensions[$theme]->getPath() . '/config/';
      foreach (['install', 'optional'] as $dir) {
        foreach (glob($base . $dir . '/*.yml') ?: [] as $file) {
          $provider = strtok(basename($file), '.');
          if ($provider && $moduleList->exists($provider)) {
            $providers[] = $provider;
          }
        }
      }
    }

    return array_values(array_unique($providers));
  }

  /**
   * Reads the install payload.
   *
   * @return array
   *   The decoded payload.
   *
   * @throws \RuntimeException
   *   When the payload is missing or unreadable, rather than quietly
   *   installing a default set that the test did not ask for.
   */
  protected function payload(): array {
    if (!is_file(self::PAYLOAD)) {
      throw new \RuntimeException(sprintf('Neo test setup payload not found at %s. Install the site with the drupalInstallNeo() command rather than drupalInstall().', self::PAYLOAD));
    }
    $decoded = json_decode((string) file_get_contents(self::PAYLOAD), TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf('Neo test setup payload at %s is not valid JSON.', self::PAYLOAD));
    }
    return $decoded;
  }

}
