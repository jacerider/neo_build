# Neo build — internals and low-frequency commands

Read this when the main skill's answer needs a mechanism behind it, or when a question lands on
one of the rarer commands. Everything here is checkable at the cited location.

## What the dev-server watcher does on save

`tools/neo-vite-plugin.ts` `handleHotUpdate()` has exactly three matcher tiers, checked in order:

| Saved file | Response |
|---|---|
| `**/*.php` | full page reload only |
| `**/*.twig`, `**/*.module`, `**/*.theme`, `**/*.component.yml` | `drush neo-cc`, then a full page reload |
| `**/*.info.yml`, `**/*.libraries.yml` | `drush neo-cc && drush neo <active scope>` — regenerates the manifest for the scope being served |

Source stylesheets and scripts have **no matcher here at all**; they fall through to Vite's own
hot-update path. So while the server is up the user does not need to run cache clears or the
prepare command by hand for any of the above.

The `[neo] Page reload...` line is printed by the first two tiers only. Vite prints its own
reload/HMR lines as well, so **do not treat a reload line in the terminal as evidence about which
file was processed.**

Note on `@apply` stylesheets: the plugin's `transform` hook rewrites any source file containing
`@apply` (and not already containing `@reference`), prepending an `@reference` to that scope's
primary entry stylesheet plus the Neo Tailwind plugin. Edits to imported partials often surface as
a full page reload rather than an in-place CSS swap. Whether the rewrite is what defeats
self-acceptance has not been established — don't assert a cause. Either way the change is live
after the reload; it is not a sign the watcher missed the edit.

## `neo:build:cc` (`neo-cc`) — the lighter cache clear

Lighter than `drush cr` but not surgical: it invalidates the Twig environment and the `rendered`
tag, empties the render / dynamic_page_cache / page bins, invokes every module's `cache_flush`
hook, and clears all plugin definitions. It is what the watcher runs on twig/component saves.

Prefer plain `ddev drush cr` when prescribing to a user — it is the name everyone already knows,
and naming a second cache command buys nothing.

## Dev server addressing

- Vite binds `0.0.0.0` on the port from `neo.json` (`port`, default `5173`) inside the container.
- `.ddev/config.yaml` exposes it under `web_extra_exposed_ports` as
  `{ name: vite, container_port: 5173, http_port: 5172, https_port: 5173 }`.
- Both probes — the CLI's `getDevServerPort()`/`devServerRunning()` (HTTP GET on `/@vite/client`)
  and `DrushCommands::devServerAnswering()` (a TCP connect) — read the configured port, **but
  `NeoBuild::getViteDevServerUrl()` concatenates a literal `:5173/`** onto
  `$_ENV['DDEV_PRIMARY_URL_WITHOUT_PORT']`. Changing the port therefore breaks asset delivery to
  the browser even though detection keeps working. Flag this if anyone proposes changing the port.
- Because the probe is "anything answering on the port", any unrelated listener there reads as a
  dev server — that is the `ORPHANED` state.

## How a library is rewritten in each mode

`NeoBuild::rewriteLibraryForDev()` replaces each Neo library's CSS path with
`<dev-server-url><src path>` and moves it into the library's **`js`** array with
`type: external`, `attributes: {type: module, neoCss: TRUE}` — so under dev the stylesheet arrives
as a module script from the dev-server origin and there is no `<link>` for it at all. JS is
rewritten the same way. `rewriteLibraryForProd()` instead looks each source path up in
`<theme>/dist/manifest.json` and substitutes the compiled path. This is the observable difference
between the two modes on a rendered page.

## `neo.json` (root, generated, untracked)

The bundler's input, imported by `vite.config.ts`. Holds `host`, `port`, `https`, `dev` (the
Drupal-side dev flag at prepare time), `root`/`docRoot`/`neoRoot`, `primaryRoot` and `primaryFile`
(the scope's theme and its entry stylesheet), `scope`, watcher `ignored` globs, the `tailwind`
source/import lists and `vite.lib` — the entrypoint list Vite compiles. `tools/neo-vite.cjs`
`buildConfig()` sets `outDir: './' + neo.primaryRoot + '/dist'`, which is why each scope writes
into its own theme's `dist/`.

## Dev-tracking lifecycle commands

- `neo:build:dev:enable` (`neo-dev-enable`) — sets the Drupal dev state, writes
  `.git/hooks/pre-commit` from the module's `git.pre-commit.txt`, and writes `_neo.lock`.
- `neo:build:dev:disable` (`neo-dev-disable`) — clears the state flag only. Touches nothing on
  disk.
- `neo:build:dev:cleanup` (`neo-dev-cleanup`) — **deletes `.git/hooks/pre-commit`
  unconditionally**, with no ownership or content check, deletes `_neo.lock`, and writes
  `neo_build.info` `versions.*` for every extension.

`runViteProd()` calls `cleanup()` after looping every scope, so cleanup is the last step of every
whole-project build and therefore of every dev session. The single-scope path (`build:front` /
`build:back`) returns before it and so leaves the hook and lock file alone — that is a fact about
*which files get deleted*, and says nothing about whether a build is safe to start: `runViteProd()`
and the single-scope path are guarded identically against a live dev server, and both disconnect a
running session if forced past it.

The `--claude` option on `neo:build:install` additionally installs a personal phpcs hook into
`.claude/hooks`, merges a `PostToolUse` entry into `.claude/settings.local.json`, and appends to
the ignore file.

## `drush neo:template` (`neo-template`)

Copies one of `neo_base`'s `templates/*.html.twig.example` files into `front` or `back` as
`…--<variation>.html.twig`. Fully interactive (theme, template, variation name are all prompts),
so it cannot be driven from a non-interactive shell. Unrelated to compilation.

## `drush neo:build:scopes` (`neo-scopes`)

Lists the scopes, but filters on theme context: it drops `front` when the active theme is the
admin theme, and drops `back` when no admin theme is configured. The whole-project build iterates
this list, so its coverage is execution-context-dependent. On this project both scopes are
returned, so the effect is latent.

## Compiled JS chunk shape

`neoVitePost.renderChunk` wraps chunks that declare top-level bindings in an IIFE so they can be
served as ordinary classic scripts (`rewriteLibraryForProd` no longer applies `type="module"`,
because a deferred module script voids Drupal's library dependency ordering). A chunk that
genuinely imports or exports is left unwrapped and logs a warning — it would need `type="module"`
to load, which is no longer applied. Relevant only when a `dist/` chunk throws at load time or
runs in the wrong order.

## Things that are supported but unused here

- The `components`, `theme`, `base` and `variants` Tailwind extension keys are read by
  `NeoExtension::getTailwindInfo()` but no info file in this repo uses them. Only `utilities` has
  evidenced usage, with the nested `apply:` shape. Don't present the README's alternative shapes
  as confirmed.
- A `group` key appears in `neo_base.info.yml`'s `neo:` block and the README mentions a build
  group, but no code under the module reads it. Treat it as vestigial.
