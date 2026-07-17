CONTENTS OF THIS FILE
---------------------

 * Introduction
 * Requirements
 * Installation
 * Usage
 * Build for DEV
 * Build for PROD
 * Build Groups
 * Configuration


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
