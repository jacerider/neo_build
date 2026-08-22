CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Usage
 * Build for DEV
 * Build for PROD
 * Build Status
 * Browser Tests
 * Shared Test Helpers
 * Build Scopes
 * Components
 * Theme
 * PHPStan Configuration


INTRODUCTION
------------

[Vite](https://vitejs.dev) integration for Drupal asset libraries.


REQUIREMENTS
------------

This module requires no modules outside of Drupal core.

It's designed to work with [Vite](https://vitejs.dev) 3 or newer and
[Tailwind](https://tailwindcss.com/) and
[Typescript](https://www.typescriptlang.org/).


INSTALLATION
------------

Install as you would normally install a contributed Drupal module. Visit
https://www.drupal.org/node/1897420 for further information.

Add to .gitignore:

```
# Neo
/neo.json
/neo.tsconfig.json
```

This module was built to be used with DDEV for local development. Add the
following to .ddev/config.yaml:

```
web_extra_exposed_ports:
  - name: vite
    container_port: 5173
    http_port: 5172
    https_port: 5173
```


USAGE
-----

 * Enable the module.
 * Run `drush neo-install` from site root.
 * Run `npm install` from site root.

 * In the `<theme|module>.libraries.yml`, for the library you would like to use
   assets build by Neo, add property `neo: true` and when defining assets
   provide their paths to entry points used in neo instead of paths to build
   assets. For example:

```diff
library-name:
+  neo: true
   js:
-    dist/script.js: {}
+    src/script.ts: {}
   css:
     component:
-      dist/style.css: {}
+      src/css/style.css: {}
   dependencies:
     - core/drupalSettings
```

 * The module will dynamically rewrite assets paths to dist and include
   their dependencies defined in manifest.json.

BUILD FOR DEV
-------------

To use hot module reload during development, run:

```shell
npm start
```

The server will run in non-HTTPS mode to avoid XSS issues. If the server is
accessible on the localhost under default port (5173) the module will
automatically start using it instead of dist assets from manifest.json as soon
as you clear the cache (library definitions are cached by default).

BUILD FOR PROD
--------------

To compile js and css for all scopes (only 'contrib' group), run:

```shell
npm run deploy
```

To compile a single scope non-interactively, run:

```shell
npm run build:front
npm run build:back
```

Production builds refuse to run while a dev server is answering on the vite
port — the running dev session already serves changes via HMR, and a prod
build would silently disconnect it. Pass `--force` to override.

BUILD STATUS
------------

To see whether dev (HMR) mode is on, which scope the dev server is serving
and whether a dev server is actually answering, run:

```shell
drush neo-status
```

Use `--format=json` for machine-readable output.

BROWSER TESTS
-------------

Neo drives Drupal core's Nightwatch runner. Tests live in any module or theme
under `tests/**/Nightwatch/{Tests,Commands,Assertions,Pages}` and are
discovered automatically — nothing needs registering. Tag a suite with the
module's machine name to be able to run it on its own.

```shell
npm start test              # every suite except core's
npm start test neo_modal    # one module, matched on @tags
```

On DDEV the same thing is available as a ddev command:

```shell
ddev nightwatch neo_modal
```

`drush neo-install` scaffolds `.ddev/commands/web/nightwatch` when it is
missing. An existing copy is left alone, so local edits survive.

Assets are built first. Nightwatch drives a real browser, so without that step
it would test whatever stale `dist/` happens to contain and report a pass for
code that was never compiled. Pass `--no-build` when the assets are known to be
current.

Tests are refused outright while a dev server is answering: in dev mode the
page loads HMR output for a single scope instead of the compiled assets that
ship, so the run would not be testing what deploys. Stop the dev session first,
or pass `--force` to test against the dev server deliberately.

The first run installs Drupal core's own JS dependencies (yarn, activated
through corepack) and writes `web/core/.env`, which core's runner requires to
exist even when the environment already supplies every variable. Both are
one-time.

A webdriver is required. On DDEV:

```shell
ddev add-on get ddev/ddev-selenium-standalone-chrome
```

SHARED TEST HELPERS
-------------------

This module ships Nightwatch commands and assertions that any Neo package can
use. Core's discovery scans the whole codebase, so they are available in every
suite without an import.

 * `browser.drupalInstallNeo({modules, theme, adminTheme})` — install a
   throwaway test site carrying Neo's themes and the requested modules. It
   walks the theme's base-theme chain and installs both the modules those
   themes depend on and the modules providing their shipped config, none of
   which a Neo site theme declares directly.
 * `browser.neoWaitForAnimations(selector)` — wait for `neo-animate--*` classes
   to clear. An element is visible long before it has finished opening, and Neo
   hangs real work off the animation-completion callback, so asserting straight
   after `waitForElementVisible()` races it.
 * `browser.neoPressKey(key)` — send a key. Nightwatch's own `.keys()` is
   deprecated and silently does nothing under the W3C protocol.
 * `browser.neoWaitForAjax()` — wait for Drupal AJAX to settle.
 * `browser.assert.neoAssetsBuilt()` — assert the page is serving compiled
   assets rather than the dev server, for runs started outside `npm start test`.

A project root `package.json` with `"type": "module"` makes Node load these
files as ES modules, breaking `module.exports`. Because Nightwatch loads every
discovered command and assertion regardless of the tag filter, a single module
shipping CommonJS aborts the entire run. Renaming to `.cjs` is not an option —
the discovery glob only matches `*.js` — so `npm start test` writes a
`{"type": "commonjs"}` marker into any Nightwatch directory lacking one. It is
additive and idempotent, and re-applies itself after Composer strips it from a
contrib module.

BUILD SCOPES
-------------

In `<theme/module>.libraries.yml` there is also an option to set the build
scope. The scope impacts Tailwind so that aggregated classes are only built
for the build of this scope when calling `@tailwind base;` within a CSS or
SCSS file. For example:

```yaml
library-name:
  neo: {scope: 'front'}
  js:
    src/script.ts: {}
  css:
    component:
      src/css/style.css: {}
    dependencies:
      - core/drupalSettings
```

A theme should define their supported scopes in their info.yml.

```yaml
neo: { scope: front }
```

or

```yaml
neo: {
  scope:
    - front
    - back
}
```

If a scope is not defined, theme will act on all scopes.

COMPONENTS
----------

You can register new Tailwind components by defining them in your theme/module
info file. For example:

```yaml
neo:
  scope: back
  components:
    .container:
      '@apply mt-6 first:mt-0 rounded-sm border': {}
    .card:
      backgroundColor: colors.white
      borderRadius: borderRadius.lg
      padding: spacing.6
      boxShadow: boxShadow.xl
```

THEME
-----

You can extend the Tailwind base configuration by defining the settings in your
theme/module info file. For example:

```yaml
theme:
  extend:
    colors:
      current: 'currentColor'
```

PHPSTAN CONFIGURATION
---------------------

Prepare (`drush neo:build <scope>`) generates `phpstan.neon` at the project
root — one of its four artifacts — so the site's PHPStan run covers the Neo
code it is built from. The file is regenerated by every prepare; do not edit it
by hand.

 * Analysed extensions (`paths`): every Neo extension (a module or theme with a
   `neo:` key in its info file or a Neo library), plus every enabled module or
   theme whose info file declares `package: Neo` (exact match), plus
   `modules/custom` when it exists. A disabled `package: Neo` extension is not
   analysed. `neo_build` itself and the PHP-only Neo packages — the ones with no
   Neo libraries — are therefore analysed on every site.
 * Exclusion rule (`excludePaths`): within each analysed extension's directory,
   any nested extension whose declared dependencies are not all installed on
   disk is excluded, because PHPStan cannot resolve the classes it extends and
   refuses to ignore that. Installed-but-disabled nested extensions stay
   analysed. It is a rule about resolvability, not a list of files.
 * `vendor/mglaman/phpstan-drupal/extension.neon` is included only when
   `phpstan/extension-installer` is not installed: the installer registers that
   file itself, and PHPStan refuses to run when a file is included twice.
 * Level 1, the php/module/inc/install/theme file extensions and the four
   `ignoreErrors` patterns are fixed.

The `neo_build_entity_print` submodule swaps `entity_print`'s asset renderer so
DEV-mode prints pick up Neo's CSS-as-JS assets. It depends on `entity_print`;
`drush updatedb` enables it on sites where `entity_print` is enabled, and the
exclusion rule keeps it out of analysis everywhere else.
