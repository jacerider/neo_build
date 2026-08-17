const fs = require('fs');
const path = require('path');

/**
 * Install a throwaway Drupal site with Neo's themes and the requested modules.
 *
 * drupalInstall() builds a prefixed test site from the minimal
 * `nightwatch_testing` profile, which has neither Neo nor a Neo theme. Every
 * package would otherwise ship a near-identical PHP setup file just to install
 * its own dependencies, so this centralises that into one shared setup class.
 *
 * Installing the themes is not optional dressing. Neo compiles its assets per
 * scope into the theme that owns the scope (front/back), so a test site left on
 * the profile's default theme serves no Neo CSS or JS at all — and every
 * assertion fails for a reason unrelated to the code under test.
 *
 * Prefer the install-free style where you can: pointing tests at the running
 * site is faster and exercises the configuration people actually use. Reach for
 * this when a test needs a known-clean site — a deterministic CI run, or
 * behaviour that depends on config the dev site does not have.
 *
 * The setup class cannot take constructor arguments, so the options are handed
 * over through a JSON file in /tmp. That assumes Nightwatch and PHP share a
 * filesystem, which holds when the runner is invoked inside the web container
 * (`ddev nightwatch`, `neo test`) — the supported path.
 *
 * This provides a custom command, .drupalInstallNeo()
 *
 * @param {object} [settings={}]
 *   Settings object.
 * @param {Array} [settings.modules=[]]
 *   Modules to install. Their config/install is imported with them.
 * @param {string} [settings.theme='front']
 *   Theme to install and set as the default.
 * @param {string} [settings.adminTheme]
 *   Theme to install and set as the admin theme.
 * @param {string} [settings.installProfile='nightwatch_testing']
 *   The install profile to build the site from.
 * @param {function} callback
 *   A callback which will be called.
 * @return {object}
 *   The 'browser' object.
 */
exports.command = function drupalInstallNeo(settings = {}, callback) {
  const self = this;
  const payload = {
    modules: settings.modules || [],
    theme: settings.theme === undefined ? 'front' : settings.theme,
    adminTheme: settings.adminTheme || null,
  };

  // Written before drupalInstall() so the setup class can read it during the
  // install it is about to trigger.
  fs.writeFileSync(
    '/tmp/neo-nightwatch-install.json',
    JSON.stringify(payload, null, 2),
  );

  this.drupalInstall({
    setupFile: path.resolve(__dirname, '../../TestSite/NeoTestSiteSetup.php'),
    installProfile: settings.installProfile || 'nightwatch_testing',
  });

  if (typeof callback === 'function') {
    callback.call(self);
  }
  return this;
};
