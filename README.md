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
/.stylelintcache
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
+      src/scss/style.scss: {}
   dependencies:
     - core/drupalSettings
```

 * The module will dynamically rewrite assets paths to dist and include
   their dependencies defined in manifest.json.

BUILD FOR DEV
-------------

To use hot module reload during development, run:

```shell
npm run build
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
      src/scss/style.scss: {}
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

BUILD GROUPS
-------------

In `<theme/module>.libraries.yml` there is also an option to set the build
group. The group controls what libraries are proccessed during dev. If no
group is specified for a library, `custom` is used by default. By default, there
are only two groups; 'custom' and 'contrib'. For example:

```yaml
library-name:
  neo: {group: 'custom'}
  js:
    src/script.ts: {}
  css:
    component:
      src/scss/style.scss: {}
    dependencies:
      - core/drupalSettings
```

The `contrib` group should be used for contrib modules or themes.

SCSS GLOBAL INCLUDES
-------------

Sass stylesheets can be exposed so they can be loaded in other stylesheets
using the `@use` rule.

```yaml
global:
  version: VERSION
  # Dependencies is required even if it is empty if the library does not provide
  # any other js or css declarations.
  dependencies: []
  neo:
    # For optional inclusion.
    include:
      # Include all files in folder.
      src/css/global: {}
      # Include individual file.
      src/css/global/neoBuildTestMixins.scss: {}
    # For required inclusion. Specifying include is not needed. Specifying
    # a namespace is optional. If namespace is not set, the parent directory
    # name and filename will be used. For example: includeNeoBuildTestInclude
    require:
      src/css/global/neoBuildTestMixins.scss: {namespace: testMixins}
```

To use includes within a Sass stylesheet:

```scss
// The @use statement is not needed when using 'require'.
@use 'neoBuildTest/mixins' as neoBuildTestMixins;

body {
  @include neoBuildTestMixins.neo-build-test-bg;
}
```

CONFIGURATION
-------------

In library definition instead of only enabling neo support by setting
`neo: true` theres also an option to provide some custom configurations.

```yaml
neo:
  # By default true, if not set explicitly to false assumed true.
  enabled: true
  # Path to manifest.json, by default `manifest.json`.
  manifest: 'module/dist'/manifest.json'
  # By default `<path_to_module|theme>/dist/`.
  baseUrl: '/themes/custom/my_theme/dist/'
  # Vite dev server url, by default http://localhost:5173.
  devServerUrl: 'http://localhost:9999`

```

These settings can also be overwritten in site settings.php:

```php
$settings['neo'] = [
  // By default (FALSE) the module will not check if neo dev server is running.
  // Settings this to TRUE will automatically check if the server is running and
  // serve neo assets fromm the server when appropriate.

  // By default (FALSE) the module will not automatically check if neo dev
  // server is running and if so, use it. Settings this to false will make sure that
  // neo dev server will not be used, which is recommended setting for
  // production environments.
  'useDevServer' => TRUE,
  // Global overrides.
  /* Make note that these are global so they will take effect for all drupal
   * asset libraries, so setting enabled => TRUE here is not really recommended.
   * Probably the only useful to set here would be devServerUrl to globally
   * override the default one.
   */
  'enabled' => TRUE,
  'manifest' => 'vue_app/dist/manifest.json',
  'baseUrl' => '/some/custom/base/url/used/in/production/for/dist/assets/',
  'devServerUrl' => 'http://localhost:5173',
  'overrides' => [
    // Per module/theme overrides.
    '<module|theme>' => [
      // ... settings like the global ones
    ]
    // Per library overrides.>
    '<module|theme>/<library>' => [
      // ... settings like the global ones
    ]
  ],

]

```

In `<theme/module>.libraries.yml` there is also an option to disable rewriting
of specific asset, to do that you need to set `neo: false` for specific asset.
For example:

```diff
 global-styling:
   neo: true
   js:
-    some/static/script.js: {}
+    some/static/script.js: {neo: false}
   css:
     component:
       src/scss/style.scss: {}
     dependencies:
       - core/drupalSettings
```

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
