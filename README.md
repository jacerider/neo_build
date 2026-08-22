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

Every build step's status is read, and a prod build exits non-zero when a fatal
step fails. The fatal steps are the dev-mode toggle, prepare, the compile and
the type check. The run stops at the first failing scope: a failure has already
rewritten that scope's generated `neo.json` and `neo.tsconfig.json`, so the
remaining scopes would build on a known-bad footing. The success line prints
only once every scope has succeeded.

The failure report names the scope, the failing step, and whether `dist/` was
written:

```
✘ [neo] Build failed in the front scope: the type check (exit status 2).
[neo] dist/ was written - the compiled assets are good; the type check is a
separate gate.
```

That last line matters. esbuild strips types without checking them, so the type
check is the only type gate the stack has, and it runs after the compile. A type
check that fails on a successful compile means the assets in `dist/` are good —
reporting them stale when they are not would be its own bug.

The exit status is the failing step's own status when that is in the range
1-255, and 1 otherwise — a signal-terminated fatal step, or a status that could
not be read.

Quitting the dev server is a **signal exit**, not a failure. It still falls
through to the production rebuild and exits 0, exactly as it always has. A dev
server that cannot start — an occupied port, say — is a genuine failure: it is
reported as the dev server failing, and no rebuild follows it.

Build cleanup runs on every path, so a failed build still unlocks and `_neo.lock`
and the generated pre-commit hook are removed either way. The compiled-versions
stamp is the half that does not run on a failure: that record is committed and
shared with the team, and it must never claim `dist/` was built from versions it
was not. The same split is available by hand:

```shell
drush neo-dev-cleanup --skip-stamp
```

There is deliberately no flag that restores the old always-exit-0 behaviour.

### The generated stylesheet

Prepare writes `tailwind.neo.css` beside each scope's primary CSS file — the
entrypoint that imports `"tailwindcss"`. It is generated; do not edit it. Its
sections are emitted in a fixed order:

1. the file header and the `@plugin` line
2. `@source` lines
3. the `@theme` block — every custom property, and the only place one goes
4. top-level rules, unsorted, in insertion order
5. `@layer components` — the one layer, its rules in comparator order
6. `@custom-variant` lines
7. `@import` lines

Two of those will look wrong to a reader who knows CSS, and neither is.

**`@utility` rules are top-level, outside any layer.** Tailwind 4 does not
register an `@utility` declared inside a layer, so folding the bucket into a
default layer would silently drop every icon, card, badge and container
utility. The bucket is unsorted for a related reason: Tailwind resolves
`@utility` cross-references from its own registry rather than by file order.

**`@import` comes last, not first.** The artifact is never served to a browser
— Vite and Lightning CSS inline each import where it appears — so the position
is free to carry meaning, and it carries override precedence: an imported
stylesheet is inlined after the generated `@theme` block, which is what lets a
theme's token beat the same token from a module's build-event subscriber.
Moving it to the top inverts that silently. The reasoning is in the
`TailwindStylesheet` class docblock.

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

Tests are also refused when the Drupal-side status cannot be read at all. A
status command that *failed* is not the same as one that succeeded and reported
nothing: an unreadable status cannot say whether dev mode is on or a dev server
is up, and treating it as clean would wave the run straight past the guard that
exists to stop it. A status that was read and is simply empty still passes.

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

**The scope set is closed.** There are exactly two scopes — `front` and `back` —
and they are defined in one place, the `Drupal\neo_build\Scope` enum, which
carries each scope's id, label, description and theme name. There is no YAML to
ship and no alter hook to implement: a scope cannot be added from outside this
module, and until the enum replaced it, the plugin type that appeared to allow
it never actually could.

**A scope's id is the machine name of the theme it compiles into.** That is the
rule, not a coincidence of naming, and `Scope::themeName()` is where it is
stated. The `front` scope compiles into the `front` theme, `back` into `back`.

Adding a third scope is a real piece of work rather than a line of
configuration, which is why the set is closed rather than extensible. It needs,
at minimum:

* a **theme** whose machine name is the scope's id,
* a **base theme** for it to extend,
* a **primary file** — the CSS entrypoint carrying `@import "tailwindcss"`,
  without which the scope compiles no Tailwind at all,
* an **inline library**, so the scope's generated stylesheet reaches a page,
* a **settings entry**, for the modules that style per scope.

Shipping YAML would have supplied none of those. If you genuinely need a third
scope, add a case to the enum and work through that list.

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

If a scope is not defined, the default depends on the extension type: a
**module** falls into every scope, and a **theme** falls into `front` alone.

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
