---
name: neo-build
description: Understand and drive the Neo asset build — the Vite + Tailwind + TypeScript pipeline that compiles theme/module assets per scope (front/back) — and its Nightwatch browser-test runner. Use when editing *.ts / src CSS / *.libraries.yml `neo:` entries or info.yml `neo:`/`theme:` keys, when a class or asset change "isn't showing up", when deciding whether to run `drush neo:build` / `npm start` / `npm run deploy` / `drush cr`, when working with the Vite dev server, build scopes, or `.claude/skills` installation, or when running or writing browser tests (`npm start test`, `ddev nightwatch`, `tests/**/Nightwatch/`). NOT for authoring components (use neo-component) or module PHP (use neo-alchemist-dev).
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# The Neo asset build

`neo_build` is a [Vite](https://vitejs.dev) + [Tailwind](https://tailwindcss.com) + TypeScript pipeline for Drupal asset libraries. It lets a library point at **source** entrypoints (`src/*.ts`, `src/css/*.css`) and rewrites them to compiled `dist/` assets at render time via a manifest — or serves them live from a Vite dev server during development.

Full human reference: [web/modules/contrib/neo_build/README.md](web/modules/contrib/neo_build/README.md). This skill is the operational layer: *what to run after editing what, and why a change isn't showing.*

> Authoring a page-building component (`*.component.yml` / `*.twig`)? Use **neo-component**. Editing neo_alchemist PHP? Use **neo-alchemist-dev**. This skill is only about the asset build.

## Mental model

- **`neo: true` libraries** — in a `*.libraries.yml`, a library flagged `neo: true` (or `neo: {scope: front}`) lists `src/` entrypoints instead of `dist/`. The module swaps them for the compiled `dist/` assets (and pulls in their dependencies) using `manifest.json`. When the Vite **dev server** is up, it serves the live source instead.
- **Scopes** — the build is partitioned into scopes: **`front`** (frontend theme) and **`back`** (backend/admin theme). **Admin pages render with the `back` theme.** **The set is closed** — it is the `Drupal\neo_build\Scope` enum, not YAML and not an alter hook, so there is no scope to discover beyond these two, and **a scope's id is the machine name of the theme it compiles into**. An extension declares its scopes in info.yml (`neo: { scope: [front, back] }`); with none declared a **module** falls into every scope and a **theme** into `front` alone. List them with `drush neo-scopes`.
- **How an entrypoint resolves** — `neo_build.manifest_resolver` derives the **active scope** for the request, maps it to that scope's `dist/`, and looks the entrypoint up there. The rule is: the active theme's machine name is a scope id → that scope; else the active theme is the admin theme → `back`; else `front`. It is derived per request and never persisted (site repo `docs/adr/0002-render-time-scope-is-derived-not-persisted.md` records why). **A library declared in one scope resolves against that scope**, not against whichever theme is rendering — so a front-only library on an admin page now gets front's built file instead of silently shipping its raw `.ts`/`.css` source. A library in both scopes still follows the active scope, unchanged.
- **Tailwind is scope-isolated** — `@tailwind base` only emits the classes aggregated **for that scope**. A utility used only in a `front` library will **not exist** in the `back` build's CSS, and vice versa. This is the #1 cause of "my class does nothing": the class is real but the owning scope wasn't rebuilt (or you used it in the other scope).
- **Two-step build** — `drush neo:build <scope>` (alias `neo`) is **prepare**: it writes the four build artifacts — `neo.json`, `neo.tsconfig.json` and `phpstan.neon` at the project root, and `tailwind.neo.css` beside the scope's **primary file** (the CSS entrypoint that carries `@import "tailwindcss"`). Vite then compiles to `dist/`. The npm CLI orchestrates both: it calls `drush neo <scope>` then runs Vite. A scope with no primary file gets a `⚠ [neo] No primary CSS file in this scope …` warning and no `tailwind.neo.css`; the other three artifacts are still written.
- **Generated** — `neo.json` is generated and git-ignored. **`neo.tsconfig.json` is generated but TRACKED**, so every build shows up as a diff. It is only the `include` list, and a single-scope build (`npm run build:front`) rewrites it for *that scope alone*, silently dropping the other scope's entries — run `npm run deploy` before committing it, or the back scope loses its files. `_neo.lock` is the dev-mode lock file.

## Step 0 — is the dev server running?

Before running **any** build, check `drush neo-status` (`--format=json` for scripting). The `status` field is the verdict:

- **DEV** — a dev server is live. Changes in its scope are already served via HMR: src TS/CSS, `.twig`, `.component.yml`, `.php`, `.info.yml` and `.libraries.yml` edits are all watched, with cache clears and `neo.json` regeneration handled for you. Run **nothing**. Changes in the *other* scope still serve stale `dist/` — but a prod build would disconnect the dev session, so coordinate with the user instead of forcing one (Ctrl+C on their watcher terminal auto-runs a full prod build of all scopes).
- **PROD** — no dev server. Build normally: `npm run build:front` / `npm run build:back` for one scope, `npm run deploy` for all.
- **STALE** — dev state is on but the server died without cleanup. `npm run deploy` restores `dist/` assets.
- **ORPHANED** — a server is answering but Drupal isn't using it. Restart `npm start` or stop the server.

The probe behind `dev_server_up` is an HTTP GET for `/@vite/client`, the same request the build CLI makes, so **Drush and the CLI now agree on what counts as a dev server** — only a real Vite server answers it. Drush used to open a raw TCP socket, so any process on the port read as DEV. `dev_server_url` reports the URL, or the reason there is none when `DDEV_PRIMARY_URL_WITHOUT_PORT` is unset.

Prod builds **refuse to run** while a dev server is answering (override: `--force`). The refusal is not an error to work around — it means the change is already live.

## What do I run after editing…?

| You changed | Do this |
|---|---|
| A component `.twig` only (no new classes) | Nothing to compile — Twig isn't built. Dev watcher live-reloads the preview. `drush cr` only if Twig is cached. |
| `.component.yml` (props/slots) | `drush cr` — SDC + prop defs are cached. |
| A **new Tailwind class** in a `front` component/template | Rebuild the **front** scope. Dev server running → HMR picks it up. Otherwise `npm start` (choose front) or `drush neo:build front` + Vite build. |
| A class in an **admin/back-theme** asset | Rebuild the **back** scope (admin = back theme). Both scopes if it's shared. |
| `src/*.ts` or `src/css/*.css` in a `neo: true` library | Dev server → HMR. For committed output: `npm run deploy` (all scopes) or `npm start` (one). |
| `*.libraries.yml` or info.yml `neo:`/`theme:` keys | Dev server running → watcher handles it (cache clear + `neo.json` regen). Otherwise `drush cr` first (library defs are cached), then rebuild the affected scope. |
| Added/edited a module's `install/skills/*` | `drush neo:build:install` to re-aggregate into `.claude/skills`. |
| A `tests/**/Nightwatch/*` file | `npm start test <tag>` (or `ddev nightwatch <tag>`). The test file itself needs no build, but the code it exercises does — so let it build unless you just built. |

When unsure which scope (and no dev server is running — see Step 0), rebuild **both** (`npm run deploy`) — the site here needs `front` **and** `back`, and forgetting `back` is why admin-side changes silently don't apply.

## Commands

npm (from project root — the actual compile):

⚠ **On a DDEV project every one of these is `ddev npm …`, not host `npm`.** The CLI shells out
to drush to read the scopes, so from the host it dies on `[neo] Failed to fetch scopes` — which
names neither node nor ddev and reads like a broken build config, not a missing container.

⚠ **Type-check with `ddev exec npx tsc --noEmit` — never `-p neo.tsconfig.json`.** That file is
the generated *include list* and carries no `compilerOptions`; `tsconfig.json` holds them
(`target: ES2022`, `lib: ES2022`/`DOM`) and `extends` it. Pointing tsc at the generated file falls
back to ES5 defaults and invents ~170 phantom errors — "Property 'includes' does not exist on type
'string'", "Cannot find name 'Set'", "'Promise' only refers to a type" — which read as a rotten
codebase rather than a wrong `-p`. Bare `npx tsc --noEmit` resolves `tsconfig.json` and reports 0,
so any error it does report is real.

⚠ **`drush neo:build` is NOT the build.** It writes the four artifacts and stops, and its last line
is `Prepare Success` — so it looks like a completed compile, `drush cr` afterwards looks like the
finishing step, and the page still serves the old `dist/`. The tell is the mtime on
`web/themes/<theme>/dist/front.css`: unchanged means nothing compiled. Only the npm commands
below write `dist/`.

- `npm start` — interactive: pick a **scope** and **dev vs prod**. Dev launches the Vite **HMR dev server** (port 5173 by default, `$settings['neo']['port']` to move it, exposed via DDEV); prod builds that scope to `dist/`. Non-interactive: `npm start -- <prod|dev> <scope>`.
- `npm run build:front` / `npm run build:back` — non-interactive prod build of **one** scope. Prefer these over `deploy` when you know the scope.
- `npm run deploy` — `npm start -all`: prod-build **every** scope. This is the "make it real / commit-ready" build.
- `npm run preview` — `vite preview`.
- `npm start test [tag]` — run Nightwatch browser tests (see "Browser tests"). On DDEV also `ddev nightwatch [tag]`.

All prod builds are refused while a dev server is answering (see Step 0); `--force` overrides.

⚠ **A prod build's exit status is now real — check it.** Every fatal step's status is read (the dev-mode toggle, prepare, the compile, the type check), the run stops at the **first failing scope**, and the process exits non-zero. `All builds completed successfully` prints only when every scope actually succeeded, so its presence is now evidence rather than decoration. Older builds printed it and exited 0 no matter what happened; **do not carry that assumption into a green run on an older checkout.**

The failure report names the scope, the step, and whether `dist/` was written — e.g. `✘ [neo] Build failed in the front scope: the type check (exit status 2).` followed by `dist/ was written`. The type check runs **after** the compile and is the only type gate the stack has (esbuild strips types without checking them), so a type-check failure on a good compile means `dist/` is fine and only the types are wrong. The exit status is the failing step's own when it is 1-255, and 1 otherwise.

Quitting the dev server is a **signal exit**, not a failure: it still falls through to the full prod rebuild and exits 0. A dev server that *cannot start* (occupied port) is a real failure and no rebuild follows it.

Build cleanup runs on every path, so a failed build still unlocks (`_neo.lock` and the pre-commit hook go either way). The **compiled-versions stamp** does not run on a failure — that record is committed and shared, and must not claim `dist/` was built from versions it was not. There is no flag that restores the old always-exit-0 behaviour.

**Base-layer Tailwind data is no longer accepted.** `addTailwindBase()`, `getTailwindBase()` and `clearTailwindBase()` are gone from `NeoBuildCollection`, and the stylesheet has no base layer to route to. `@layer base` reached none of the generated stylesheets on any site: `neo_font`, the only caller anywhere, had always passed an empty array. A subscriber that needs base-level declarations puts custom properties in the theme (`addTailwindThemeItem()`) and rules in components (`addTailwindComponents()`). This removed one of the eight collection signatures the preparer work published for sibling subscribers — announced rather than silent, and `NeoBuildCollectionContractTest` now pins seven.

drush (manifest + lifecycle):

- `drush neo:build:status` / `neo-status` — dev mode, dev scope, and a live probe of the dev server (`--format=json` for scripting). **Run this first.**
- `drush neo:build [scope]` / `neo` — prepare a scope (default `front`): write `neo.json`, `neo.tsconfig.json`, `phpstan.neon` and `tailwind.neo.css`, and warn when the scope has no primary file (then no `tailwind.neo.css`). Lower-level; `npm start` calls this for you.
- `drush neo:build:cc` / `neo-cc` — clear just the twig/render/page caches (lighter than full `drush cr`).
- `drush neo:build:scopes` / `neo-scopes` — list scopes (`--format=json` for scripting). Lists **every** scope, always. It used to prune by admin theme, which made `front` unreachable from `npm run deploy` on any site whose default theme is also its admin theme — so on such a site the deploy will now build a scope it has been silently skipping, and that first build may show a large `dist/` diff for it. That is the fix landing, not a regression.
- `drush neo:build:install` / `neo-install` — (re)install `package.json` / `tsconfig.json` / `vite.config.ts`, add the DDEV vite port, **and aggregate every enabled module's `install/skills/*` into `.claude/skills/`**.
- `drush neo:build:dev:enable` / `neo-dev-enable` — turn on dev tracking (installs a git pre-commit hook + writes `_neo.lock` so dev/HMR state isn't committed).
- `drush neo:build:dev:disable` / `neo-dev-disable` — turn it off.
- `drush neo:build:dev:cleanup` / `neo-dev-cleanup` — remove the hook + lock and refresh compiled version info. `--skip-stamp` unlocks **without** the compiled-versions stamp; the build passes it automatically when a scope failed.
- `drush neo:template` / `neo-template` — scaffold a `*.html.twig` template into a theme from `neo_base`'s `.example` templates.

## Dev server vs `dist/`

`npm start` in **dev** mode runs Vite with HMR: edits to `src` TS/CSS (and watched Twig/yml) reload instantly. While the dev server is answering, the module serves **live source** and **`dist/` is intentionally stale — don't rebuild it or trust its contents in dev.** The switch is cache-gated: after starting/stopping the server, `drush cr` so the library definitions re-resolve to server-vs-dist.

The port defaults to 5173 and is **not fixed** — `$settings['neo']['port']` moves it, and the asset URL, the Drush probe and the CLI probe all read it from there. `port` is the only key `$settings['neo']` carries.

⚠ **Dev mode requires DDEV.** The dev server URL is built from `DDEV_PRIMARY_URL_WITHOUT_PORT`, and `drush neo:build:dev:enable` **refuses** when it is unset, naming the variable, rather than enabling a dev mode in which every asset 404s. It writes nothing when it refuses — no state flag, no `_neo.lock`, no pre-commit hook — and exits non-zero, which stops `npm start`. The Vite dev server refuses to start for the same reason. There is no portability off DDEV, by design.

For committed/production assets, run `npm run deploy` (builds all scopes to `dist/`). `dist/*.css` is the source of truth in prod; in dev it is not.

## Browser tests

Neo drives **Drupal core's Nightwatch runner**. Tests live at
`<module>/tests/**/Nightwatch/{Tests,Commands,Assertions,Pages}` and are discovered across the
whole codebase automatically — nothing is registered. Tag a suite with the module's machine name
(`'@tags': ['neo_modal']`) so it can be run on its own.

- `npm start test` — every suite except core's. `npm start test neo_modal` — one module, by tag.
- `ddev nightwatch neo_modal` — the same thing on DDEV. `drush neo-install` scaffolds that command
  when it is missing and leaves an existing copy alone.
- `--no-build` skips the asset build; `--force` overrides the dev-mode refusal.

⚠ **Assets are built first, and that is not optional politeness.** Nightwatch drives a real browser,
so it tests whatever the page serves. Skipping the build tests the *old* bundle and reports a
confident pass for code that was never compiled. The run is refused entirely in dev mode, because
the page would load HMR output for one scope instead of the compiled assets that ship.

First run installs core's JS deps (yarn via corepack) and writes `web/core/.env` — core's runner
uses dotenv-safe, which requires the *file* to exist even when the environment already supplies
every variable. Needs a webdriver: `ddev add-on get ddev/ddev-selenium-standalone-chrome`.

**Helpers this module ships** (available in every suite, no import):

| Helper | Why it exists |
|---|---|
| `drupalInstallNeo({modules, theme})` | Installs a throwaway site with Neo's themes. Walks the theme's base-theme chain and installs the modules those themes *and their shipped config* need — a Neo site theme declares none of it directly. |
| `neoWaitForAnimations(selector)` | Waits for `neo-animate--*` to clear. **An element is visible long before it has finished opening**, and Neo hangs work off the animation callback. |
| `neoPressKey(key)` | Nightwatch's `.keys()` is deprecated and does nothing under W3C. |
| `neoWaitForAjax()` | Waits for Drupal AJAX to settle. |
| `assert.neoAssetsBuilt()` | Asserts the page is serving compiled assets, for runs started outside `npm start test`. |

### Writing Nightwatch tests

- They are **plain CommonJS JS on your installed Node — not TypeScript**. Files outside `core/` run
  untranspiled, so no `import`, no types, unless a Babel config is added at the project root.
- Prefer **install-free** tests that drive the running site: far faster, and they exercise the
  configuration people actually use. Reach for `drupalInstallNeo()` only when a test genuinely needs
  a known-clean site.
- After opening anything animated, `neoWaitForAnimations()` before asserting.

## Generated PHPStan configuration

Prepare also writes the project's `phpstan.neon` (regenerated on every `drush neo <scope>`; never
edit it by hand). What its `paths` cover — the **analysed extensions**:

- every Neo extension (a module or theme with a `neo:` key or a Neo library), as before;
- every **enabled** module or theme whose info file declares `package: Neo` (exact match) — so
  `neo_build` itself and the PHP-only Neo packages (neo_settings, neo_twig, neo_font, …) are
  analysed; a disabled one is not;
- `modules/custom`, when it exists.

The **exclusion rule** lists under `excludePaths` any extension nested inside an analysed path whose
declared dependencies are not all on disk (PHPStan cannot resolve what it extends and refuses to
ignore that); installed-but-disabled nested extensions stay analysed. `neo_build_entity_print` —
the optional submodule that serves Neo's CSS-as-JS assets to `entity_print` in DEV mode, enabled by
`drush updatedb` where `entity_print` is on — is the standing example: excluded wherever
`entity_print` is absent. `vendor/mglaman/phpstan-drupal/extension.neon` is included only when
`phpstan/extension-installer` is *not* installed.

Run it as `vendor/bin/phpstan analyse --configuration=phpstan.neon <files>`. A site whose
`phpstan.neon` predates this rule can show PHPStan's "This file is included multiple times" abort —
re-run prepare (`drush neo front`) and the include is gone.

## Registering Tailwind components / theme config

Extend Tailwind from a theme/module info.yml rather than a config file — see README "COMPONENTS" and "THEME":

```yaml
neo:
  scope: back
  components:
    .card: { '@apply rounded-lg border shadow-xl p-6': {} }
theme:
  extend:
    colors: { current: 'currentColor' }
```

After editing these, `drush cr` and rebuild the scope.

## Common pitfalls

- **Warning: "The `<scope>` scope has no built file for the entrypoint `<path>`"** — on the `neo_build` logger channel. That scope **is** built and its manifest predates the entrypoint: prepare has not run since the entrypoint was declared. Build that scope. A scope with no manifest at all says nothing on purpose — "not built yet" is normal before a first build and during install.
- **`$settings['neo']['host']` / `['https']` do nothing — they are gone** — they never took effect (both were overwritten one line after being read) and were removed. `port` is the only key `$settings['neo']` carries. If you are trying to change the dev server's host or scheme, you are looking at the wrong lever: the URL comes from DDEV.
- **Class is real but does nothing** — Tailwind scope isolation. The class wasn't compiled into the scope actually rendering the page (or you added it to `front` but need it in `back`, or vice versa). Rebuild the owning scope; when in doubt (and no dev server is up) `npm run deploy`.
- **Build refused: "a Neo dev server is already running"** — not an error. The watcher already serves your change via HMR; at most `drush cr` if something cached. Only a cross-scope change needs a build, and that means stopping the dev session first — ask the user rather than passing `--force`.
- **Admin-side change didn't apply** — admin uses the **back** theme; you probably only rebuilt `front`.
- **Edited `dist/` in dev and saw no effect** — the dev server supersedes `dist/`; stale `dist/` in dev is normal. Change `src/`, not `dist/`.
- **`.libraries.yml` `neo:`/entrypoint change ignored** — library definitions are cached; `drush cr` (or `neo-cc`) before rebuilding.
- **New module skill not appearing** — skills only land in `.claude/skills/` when `drush neo:build:install` runs; the source lives in `<module>/install/skills/<name>/SKILL.md`, never edited directly in `.claude/skills` (that's the generated copy).
- **Tests: `module is not defined in ES module scope`** — a project root `"type": "module"` makes Node load Nightwatch files as ESM. Because every discovered command/assertion loads regardless of the tag filter, **one** contrib module shipping CommonJS aborts the whole run. `.cjs` won't help (the glob only matches `*.js`); `npm start test` writes a `{"type": "commonjs"}` marker into any Nightwatch dir missing one, and re-applies it after Composer strips it.
- **Tests: don't "fix" the CommonJS marker by renaming to `.cjs`** — the discovery glob is `**/*.js`, so a `.cjs` file is never found at all. Writing ESM instead is also a trap, verified: a test file using `export default {...}` loads without error and registers **zero tests**, and an ESM *command or assertion* throws `Cannot redefine property: rejectNodeOnAbortFailure` (Nightwatch calls `Object.defineProperty` on the export, and an ESM namespace is frozen) — which aborts the whole run. ESM named exports do work for test files, but not for the commands/assertions this module ships, so CommonJS + the marker is the only shape that works throughout.
- **Tests: a keypress does nothing** — `browser.keys()` is deprecated and silently sends nothing under W3C (which DDEV's selenium add-on enables). Use `neoPressKey()`. The Actions API via `.perform()` is the documented replacement but does not deliver here either.
- **Tests: the element is right there but interacting does nothing** — it is visible before it has finished opening, and Neo binds behaviour in the animation-completion callback (a modal binds its keyboard handlers there). Insert `neoWaitForAnimations()`. This failure is maximally misleading: the thing is on screen and simply ignores you.
- **Tests: green run that proves nothing** — if `drupalInstall`/`drupalInstallNeo` fails, the browser stays pointed at the **original** site, so assertions happily pass against the dev site. Confirm the install actually succeeded before trusting a pass. Likewise a run with stale `dist/` tests the previous build.
- **Build: a green run that proves nothing (older checkouts)** — before this behaviour landed, the CLI printed `All builds completed successfully` and exited 0 even when the compile or the type check had failed, leaving a stale `dist/`. On a current checkout the success line and the exit status are both trustworthy; on an older one, confirm the bundle's mtime rather than the banner.
- **Tests refused: "the Neo status could not be read"** — the drush status command itself failed, which is not the same as it reporting nothing. Fix the Drupal side (the message names the command); `--force` skips the guards if you truly mean to.
- **Tests refused: "Neo is in DEV mode"** — not an error to work around. In dev the page serves HMR output for one scope, not what ships. `npm run deploy`, then re-run.
- **PHPStan aborts with "This file is included multiple times"** — the site's `phpstan.neon` was generated before the include rule and the site has `phpstan/extension-installer`. Re-run prepare (`drush neo front`); do not hand-edit the file.
- **Committed `neo.json` / dev lock** — `neo.json` is generated and git-ignored; `neo.tsconfig.json` is generated but **tracked** (run `npm run deploy` before committing it so it carries every scope); `_neo.lock` means dev mode is on. Use `neo-dev-cleanup` before a production build/commit.
