# Changelog

## A library built into one scope now resolves against that scope

The render-time library rewrite used to read the **active theme's**
`dist/manifest.json` whichever scope the library belonged to. A library
compiled into only one scope and rendered in the other missed the lookup, and
the miss was silent: the library kept its declared source path and shipped a
`.ts` or unbuilt `.css` file to the browser. A front-only library on an admin
page is the ordinary way to hit it.

A new `neo_build.manifest_resolver` service now owns the whole chain — derive
the **active scope** for the request, map it to that scope's **dist root**,
hold one manifest per scope for the request, resolve the entrypoint, and report
one it could not resolve. **A library declared in a single scope resolves
against that scope**; a library declared in both still resolves against the
active scope, which is the majority case and is unchanged.

**That is the fix, and it is a behaviour change.** A site whose per-scope
manifests happen to carry the same entrypoints will see nothing different. A
site that was silently serving raw source for a single-scope library will start
serving the built file — which is what it should always have served.

The active scope is derived per request and never written down: the active
theme's machine name if it is a scope id, else `back` if the active theme is the
admin theme, else `front`. The site repository's
`docs/adr/0002-render-time-scope-is-derived-not-persisted.md` records the rule
and why a persisted scope-to-dist-root map was rejected.

**A new warning**, on a new `neo_build` logger channel, names a scope and an
entrypoint when that scope's manifest exists and does not carry it — meaning
prepare has not run since the entrypoint was declared. A scope with no manifest
at all stays deliberately silent: "not built yet" is the ordinary state of every
scope before its first build and of every site during install, and warning there
would emit one message per entrypoint.

## Dev mode refuses without DDEV rather than half-working

`drush neo:build:dev:enable` now **refuses** when
`DDEV_PRIMARY_URL_WITHOUT_PORT` is absent, naming the variable, and writes
nothing when it does: no state flag, no `_neo.lock`, no pre-commit hook. It
exits non-zero, which stops `npm start`. The Vite dev server refuses to start
for the same reason instead of composing `undefined:{port}` as its origin.

Previously the dev server URL was built by concatenating that variable with a
**hard-coded** `:5173`. With the variable unset that produced `":5173/"` — a URL
shaped well enough to be used and wrong enough that every asset 404s.

**No portability off DDEV is offered, deliberately.** `neo_build` requires DDEV
by documented design; there is no `$settings` fallback and no second
environment. What changed is that an unsupported environment now says so at the
one moment a developer is watching, instead of producing a dev mode that
silently serves nothing.

A single `neo_build.dev_server` service now owns the URL, the port and whether
anything is answering. The port comes from `$settings['neo']['port']`, retiring
the `:5173` hardcode that ignored it — so on a site that had moved the port,
both probes reported the server up while every asset URL pointed elsewhere. The
probe is now an HTTP GET for `/@vite/client`, the same request the build CLI
makes, so Drush and the CLI agree that only a real Vite server counts; Drush
used to open a raw TCP socket, where any process on the port qualified.
`drush neo:build:status`'s four verdicts — DEV, STALE, ORPHANED and PROD — are
covered by tests for the first time.

## `$settings['neo']['host']` and `['https']` are gone, and never worked

Both were read and then overwritten one line later — `host` from
`$_SERVER['SERVER_NAME']`, `https` to a hard-coded `TRUE` — so neither could
ever take effect. They flowed into the build collection and out into `neo.json`,
and the Node toolchain read neither: `neo-vite.cjs` builds its origin from
`DDEV_PRIMARY_URL`, and only `port` is ever read back out of `neo.json`. A
developer setting either key spent an afternoon on something that was
overwritten before it was used.

Deleted rather than deprecated: `NeoBuildCollection` loses `getHost()`,
`setHost()`, `getHttps()` and `setHttps()` along with the two constructor
arguments they were set from, and `neo.json` loses both keys. `neo.json` needs
no migration — it is gitignored per site and rewritten by every prepare.
`port` is now the only key `$settings['neo']` carries.

## The static state helpers are deprecated in docblock only

`NeoBuild::getNeoState()`, `setNeoState()` and `unsetNeoState()` remain as thin
wrappers over the same two state keys and keep working. They carry an
`@deprecated` docblock naming the injected replacement: `NeoBuild` now takes the
state service and answers `isDevMode()`, `getScope()`, `setDevMode()` and
`setScope()` through it.

**No runtime deprecation notice is emitted, and no removal release is named.**
A notice would fill logs across every site running this module for a call those
sites cannot fix themselves, and naming a major nobody has scheduled would be a
date this change cannot keep. Removal is a later decision.

`NeoBuild::getViteDevServerUrl()` is **deleted** rather than deprecated: it
returned a URL built from the hard-coded port, so it was already wrong on any
site that had moved it, and there was nothing worth preserving. `preventAlter`
is no longer static, and `NeoBuild` holds no static mutable state at all — which
is what made the render path reachable from a test.

## The build CLI owns its exit status — a build that used to pass may now fail

The build CLI exited 0 whatever happened. Six build steps had their status discarded, the scope
loop was never awaited, and the first scope's queued continuation called `process.exit(0)` before
anything else could report otherwise. A failed compile therefore left a stale `dist/`, printed
`All builds completed successfully`, and exited 0 — so `npm run deploy` in a script, a CI job or a
`ddev exec` wrapper reported success for assets that were never compiled.

Every **fatal step** now has its status read: the dev-mode toggle, prepare, the compile and the
type check. The compile and the type check became separate children rather than one shell command,
so a failure can name which of them failed. The run stops at the **first failing scope**, because a
failure has already rewritten that scope's generated `neo.json` and `neo.tsconfig.json` and the
remaining scopes would build on a known-bad footing. The success line prints only once every scope
has succeeded, and the process exits with the failing step's own status when that is in 1-255, and
1 otherwise.

**This means a build that used to "pass" on your site may now fail. That is the fix, not a
regression** — the failure was always there, and the only thing that changed is that you can see
it. There is deliberately **no opt-out flag**: an escape hatch would preserve exactly the silent
failure this removes. `--force` keeps its existing meaning and still only overrides the dev-server
guards.

The **failure report** names the scope, the step, and whether `dist/` was written. That last part
matters: esbuild strips types without checking them, so the type check is the only type gate the
stack has and it runs after the compile. A type check that fails on a successful compile means the
compiled assets are good and only the types are wrong; reporting them stale would be its own bug.

A **signal exit** is not a failure. Quitting the dev server with Ctrl-C still falls through to the
production rebuild and exits 0, exactly as it always has. A dev server that cannot start — an
occupied port — is a genuine failure, is reported as the dev server failing, and no longer triggers
a full production rebuild nobody asked for.

**Build cleanup** split at the seam that matters. It always unlocks, so a failed build no longer
leaves a checkout that cannot commit: `_neo.lock` and the generated pre-commit hook are removed on
every path. The **compiled-versions stamp** now happens only when the build succeeded. That record
is committed and shared with the team, and a broken build used to write it anyway — telling every
other developer that `dist/` was built from versions it was not. `drush neo-dev-cleanup` gains
`--skip-stamp` for the same split by hand; typed with no option it behaves exactly as before.

The **browser-test guard** stopped reading an unreadable status as a clean one. The Drupal-side
status was read through a helper that returned an empty object when the command *failed*,
indistinguishable from a command that succeeded and reported nothing — so a broken status waved the
run through the guard that exists to stop it. A test run is now refused when the status cannot be
read, naming that as the reason. A status that was read and is simply empty behaves as before.

## The scope set closes — BREAKING for the scope plugin type and the inline event's accessor

The build's scope set had two definitions that had to agree. A YAML plugin type — a manager, a
`neo_build.neo_build_scopes.yml`, a `plugin.manager.neo_build_scope` service and a
`neo_build_scope_info` alter hook — advertised `front` and `back` as discoverable, alterable
data; and then most of the code that actually built anything spelled the same pair as a literal
`['front', 'back']` and never asked the manager at all. The plugin type cost four moving parts
to keep answering a question two consumers asked, while every loop that mattered went around it.

There is one definition now: the `Drupal\neo_build\Scope` enum, carrying each scope's id, label,
description and theme name. Every consumer reads it — the preparer, both Drush commands, the
inline stylesheets, their libraries, and the per-extension scope default. The plugin type is
**gone**.

### BREAKING: the scope plugin type is removed, with no deprecation cycle

Removed outright:

- `Drupal\neo_build\ScopePluginManager`
- `neo_build.neo_build_scopes.yml`, including its commented-out `shared` scope
- the `plugin.manager.neo_build_scope` service
- the `neo_build_scope_info` alter hook

**No deprecation cycle, deliberately.** Across the nine sites this package runs on, no site and
no package ships a second scopes YAML, nothing implements the alter hook, and every declared
`neo: scope:` value is `front` or `back`. A deprecation period costs every one of those sites a
release and buys nothing, so this release note is the notice instead.

The extension point it advertised could not have worked anyway, which is the real argument for
closing the set rather than keeping it open. A scope is not a row of YAML: it needs a theme
whose machine name is the scope's id, a base theme, a primary CSS file carrying
`@import "tailwindcss"`, an inline library so its generated stylesheet reaches a page, and a
settings entry. Shipping YAML supplied none of them. Adding a scope now means adding an enum
case and working through that list — the same work as before, minus the impression that it was
configuration.

**Scope identity is now a stated rule.** A scope's id *is* the machine name of the theme it
compiles into, and `Scope::themeName()` is where that is written down rather than rediscovered.

### BREAKING (PHP): `NeoBuildInlineEvent::getThemeName()` is now `getScope()`

The inline event called a scope a theme name. It worked only because the two strings collide,
and it meant following any inline subscriber required knowing about the collision first.

```php
// Before.
$scope = $event->getThemeName();   // string

// After.
$scope = $event->getScope();       // Drupal\neo_build\Scope
$id = $event->getScope()->value;   // the string, where a string is what you need
```

The event's constructor takes a `Scope` and its public `$themeName` property is now `$scope`.
**There is no deprecated wrapper.** An out-of-tree subscriber that read the old accessor breaks
on upgrade, which is the intent: a wrapper would let it keep working and be discovered by
somebody else, on another site, at some later release. Every in-tree reader moved with the
rename in the same change.

### `drush neo-scopes` no longer prunes by admin theme — a site may start building a scope

The scopes command dropped `front` from its output whenever Drush's negotiated active theme
equalled the configured admin theme, and dropped `back` when no admin theme was set. The build
CLI parses that command to decide what to build, so on a site whose default theme is also its
admin theme the front scope was **unreachable from `npm run deploy`** — while `drush neo front`
prepared it perfectly happily. One such site's front stylesheet was ten months stale against a
back stylesheet three days old.

The pruning is deleted. `neo-scopes` reports every scope, always, and its JSON shape is
unchanged. **On an affected site the next `npm run deploy` will build a scope it has been
silently skipping, and that scope's `dist/` may show a large diff. That is the fix landing, not
a regression.** Both known affected sites have a real front primary file, so the scope is
genuinely buildable there and needs no guard.

`NEO_SCOPE` is gone from the build CLI's Vite invocation. Nothing on either side of the
PHP/Node boundary read it; the scope reaches Vite through `neo.json`, which prepare rewrites per
scope.

### `neo_form`'s settings form offers the scopes, not every installed theme

`neo_form` is the only reader of the inline event's scope outside this package, so it moves with
the rename in the same release. Its settings form enumerated every installed theme, with `front`
and `back` sorted to the top — but the inline generator only ever emits per scope, so every
other entry was inert: a site builder could spend an afternoon styling a theme that would never
receive a byte of it. The form offers one entry per scope now, which also retires the
sort-to-the-top special-casing.

**The stored `themes:` key keeps its name and its schema.** Renaming it to `scopes:` would have
cost a schema change and an upgrade path on every site for a key nothing misreads once the form
is narrowed. **There is no update hook and nothing to migrate** — a stray entry for another
theme is as inert after this change as it was before it.
