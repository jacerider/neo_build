---
name: neo-build
description: The Neo asset pipeline — Vite + Tailwind v4 + TypeScript compiling theme and module assets into two per-scope bundles (`front` = public pages, `back` = admin pages). Use when deciding what to run after editing a `src/**` TypeScript or CSS entrypoint, a `*.libraries.yml` `neo:` key, or an info.yml `neo:` key; when a class, style or script change "isn't showing up"; when choosing between `drush neo:build`, `npm start`, `npm run build:front` / `build:back`, `npm run deploy` and a cache clear; or when dealing with the Vite dev server, build scopes, or this pipeline's generated files. NOT for markup, layout, prop or class-choice problems inside a component (use neo-component), module PHP (neo-alchemist-dev, neo-color-dev, neo-font-dev, …), or rendering bugs with no asset change behind them.
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# The Neo asset build

*What to run after editing what, and why a change isn't showing.* Internals and the
low-frequency commands: [reference.md](reference.md).

## Ground rule — every command runs in the container

This project is DDEV-based and **there is no host-level `drush`**. Write every command as
`ddev drush …` and `ddev npm …` (or `ddev exec npm …`). Always, unhedged.

Host `npm run build:front` does not fail cleanly: host `node`/`npm` exist, so it starts, then the
CLI shells out to bare `drush neo-scopes --format=json`, gets nothing, and exits with
`[neo] Failed to fetch scopes` — naming neither the container nor the missing binary, so it reads
like a broken build config. Never offer a bare host form as an alternative ("…or just
`npm run build:back`"); there is no case where it works.

## Answer shape

Lead with what resolves the question asked, then the one or two commands. A few sentences of
mechanism, not a tour.

- **Fix first.** An adjacent concern goes *after* the fix, in a sentence — never as the headline,
  never as a reframing of what the user's real problem is.
- **No unasked hypotheses.** Don't open with a theory the user didn't raise.
- **No volunteered commands.** Every extra name is another chance to be wrong. Name the minimum
  that does the job — often `ddev drush cr` alone, or nothing.
- **No branch-per-possibility.** If one fact decides the answer, establish it or state the
  condition once.

## Is this actually a build question?

A build only ever changes **which CSS and JS bytes reach the browser**; nothing else here moves.
Markup, element order, props/slots and class *choice* are component authoring (**neo-component**);
PHP, config, content and access belong to that module's skill. This skill owns only the case where
a rule or behaviour simply isn't present in what the page received.

When the answer is "not a build problem": say so in a sentence, fix the real thing, prescribe the
minimum, and **stop**. Do not attach build hygiene to an answer that runs no build — no status
probe, no whole-project build, no generated-file caveat. Those are only correct while a build is
genuinely on the table.

## The model

- **Two scopes, exactly:** `front` and `back` (`neo_build.neo_build_scopes.yml`; a third is
  commented out). **`/admin*` renders in the `back` theme, everything else in `front`**
  (`config/system.theme.yml`: `admin: back`, `default: front`). Tailwind is scope-isolated — each
  scope's stylesheet emits only the utilities found by *that scope's* `@source` globs, so a class
  used only in back-rendered markup will never exist in `front.css`, and vice versa.
- **Compiled:** the `src/**` TypeScript and CSS entrypoints that opted-in libraries declare.
  Output lands in each extension's `dist/` (`web/themes/front/dist/front.css`, `…/back/dist/…`,
  plus per-library chunks and a `manifest.json`).
- **Not compiled:** `*.twig`, `*.component.yml`, `*.info.yml`, PHP. Twig and component files enter
  the pipeline *only* as Tailwind scanner globs — `NeoBuildCollection` adds
  `<ext>/templates/**/*.twig` (only if that directory exists at prepare time) and
  `neo_alchemist`'s subscriber adds `<ext>/components/**/*.{yml,twig}` — so their class names get
  extracted, but the files are never compiled and never copied to `dist/`. Say it that way;
  "Twig isn't part of the build at all" is wrong.
- **Two different files are called a manifest.** Root `neo.json` is the bundler's *input*
  (`vite.config.ts` does `import neo from './neo.json'`), so Vite cannot run until prepare has
  written it. `<theme>/dist/manifest.json` is the render-time *lookup table* mapping a source
  entrypoint to its compiled file.
- **Dev-vs-`dist` is decided in a cached alter hook** (`neo_build_library_info_alter()` →
  `NeoBuild::rewriteLibrary()`). So starting or stopping the dev server, or editing library
  definitions, changes nothing the browser receives until definitions are re-resolved — by
  `ddev drush cr`, or by the prepare step, which clears library discovery itself.

### Prepare is not compile

`drush neo:build [scope]` (alias `neo`, **default scope `front`**) regenerates `neo.json`,
`neo.tsconfig.json`, `phpstan.neon` and that scope's `src/css/tailwind.neo.css`, clears library
discovery, prints `⟢ [neo] Prepare Success`, and stops. It invokes no bundler and writes nothing
to `dist/`. That success line looks like a finished compile and a following `drush cr` looks like
the finishing touch — meanwhile the page still serves the old `dist/`. **Only the npm commands
compile;** `drush cr` never compiles anything either.

## The dev server governs everything — settle it first

**One scope at a time.** The dev server serves exactly one; the other keeps serving its existing
compiled output, so a change belonging to it will not appear however often you reload.

### While anything answers on the dev port, a production build is not an available action

`guardDevServer()` probes the port and **refuses** — prints `Refusing to build: a Neo dev server
is already running on port …` and exits 1 without building. Every production path goes through it,
including `npm run build:front` / `build:back` (each is `npm start -- prod <scope>`) and
`npm run deploy`. The refusal exists because a production build there disables the Drupal-side dev
state, rewrites `neo.json` and deletes `_neo.lock` — disconnecting the running session, and anyone
else on it, without warning.

So when someone is running, or about to run, a build while a dev server is up:

- **Say to stop it, and say why.** The refusal is information — usually that their change is
  already live — not an obstacle to route around.
- **Never present such a build as harmless, cheap, or a way to "force it through."** How many
  scopes a build covers has no bearing on whether it is safe to start; the disconnect is a
  property of building at all while the watcher is up.
- `--force` (e.g. `npm run deploy --force`) overrides the refusal. Never reach for it on your own
  initiative. If the change genuinely belongs to the *other* scope, lay out the options and let the
  user pick: force it and take the disconnect, or stop the dev server — not free either (below),
  but it does build every scope on its way out.

Asking for a *dev* run while one is already answering is a deliberate, safe no-op: it prints
`A Neo dev server is already running … Leaving it alone.` and exits 0.

### While the dev server serves a scope, that scope's `dist/` is not evidence

Live source is served instead; `dist/` is neither refreshed nor consulted, so it is
**intentionally stale by design**. Its contents and mtimes prove nothing in either direction —
not "the class is missing from `dist`, so the build dropped it", and not "`dist` matches `src`, so
nothing was lost". A user telling you `npm start` is running and printing reload lines has
established that state; `dist/` was never the right file to open.

Compiled output is valid evidence **only once you have established PROD for that scope**, never as
a default assumption. What *is* valid while dev is up: what the page actually loads.
`rewriteLibraryForDev()` turns each Neo stylesheet into a `type="module"` script pointing at
`<primary-url>:5173/…`, so there is no `<link>` to `/themes/front/dist/…` for those libraries at
all. If the page still loads `dist/` paths while status says `DEV`, the library definitions are
stale — `ddev drush cr`. Probing the dev-server URL from the host is not a valid check either; the
container router answers first.

### Getting the state

`ddev drush neo-status` (primary name `neo:build:status`, `--format=json`) is authoritative — it
reads Drupal's dev flag and probes the port. Four mutually exclusive states; prescribing another
state's remedy is a factual error, not a style choice:

| Status | Meaning | Remedy |
|---|---|---|
| `DEV` | server live and Drupal using it, for one scope | **nothing to build for that scope**; other scopes still serve their existing `dist/` |
| `STALE` | dev flag on, nothing answering | `ddev npm run deploy`; or `ddev drush neo-dev-disable` + `ddev drush cr` |
| `ORPHANED` | something answering, Drupal not using it | have the user restart `npm start`, or stop the server |
| `PROD` | serving compiled `dist/` | build the owning scope |

**If you cannot run commands**, say so and condition the recommendation — never silently assume
"no server". Two readable hints, neither proving a live socket: `_neo.lock` at the project root
exists only while dev tracking is enabled, and `neo.json`'s `"dev"` / `"scope"` keys are a snapshot
from the last prepare. Report what you inferred and from what.

## What to run after editing…

Under `DEV` for the owning scope the answer is usually "nothing" — the watcher handles cache
clears and manifest regeneration itself.

| You changed | Do this |
|---|---|
| `.twig` / `.component.yml` markup, props, slots — no new utility class | Nothing to compile. `ddev drush cr` if it isn't showing. |
| the same, but it introduces a utility class no source in that scope used before | Build the scope that renders the page — because of the **class**, not the file. |
| `src/**/*.ts` or `src/css/*.css` in a `neo:` library | Dev server on that scope → already live. Otherwise build the owning scope. |
| `*.libraries.yml`, or info.yml `neo:` keys | Definitions are cached: `ddev drush cr`, then build the owning scope. |
| anything that must appear on an admin screen | `back`. |
| added a brand-new component directory | Classes may not compile until the scanner re-reads it — see below. |
| added a `templates/` directory where there was none | Its glob is added only when the directory exists at prepare time; re-run prepare (any build does) first. |
| PHP, config, content, permissions | Nothing here. |

Build commands:

- `ddev npm run build:front` / `ddev npm run build:back` — non-interactive production build of
  **one** scope. Prefer these when you know the scope.
- `ddev npm run deploy` — production-builds **every** scope. Use when the change genuinely spans
  both, or the owning scope truly can't be determined, and say it's the deliberate fallback. It
  does work: `package.json` defines it as `npm start -all`, which the CLI reads from npm's config
  environment — don't read the positional parser and conclude it's broken.
- `ddev npm start` — prompts for whatever you didn't supply, so a bare invocation hangs a
  non-interactive shell. `ddev npm start -- dev front` runs the dev server in the **foreground
  indefinitely**; never launch it from an agent shell.

## "It isn't showing up" — pick the cause

The class is on the element in devtools and nothing happens. In order of likelihood:

1. **Never compiled into the scope that renders this page** — the usual cause. Which theme renders
   it? `/admin*` → `back`.
2. **Right scope, but the browser isn't getting what you think** — dev↔`dist` swaps and library
   edits need `ddev drush cr`. Check the asset URLs on the page (above).
3. **Right scope, stale source scan.** Known trap: a *newly created* component directory's classes
   are not picked up by an already-running dev server even though the `components/**` glob covers
   it — the scanner's directory list is stale, and pre-existing classes keep working, so it looks
   like an invalid class name. Touch that scope's authored entry stylesheet
   (`web/themes/front/src/css/front.css`) to force a rescan.
4. **Compiled fine, but the rule loses** — specificity, a competing rule, the wrong utility. That
   is component authoring, not this pipeline.

A terminal reload line proves only that the watcher saw *a* change, not that your file was
processed. A build's success message is not confirmation the change is live — if you haven't
verified, say what would.

Never tell someone their class name is wrong when the evidence is equally consistent with the
class never having been compiled into the scope that renders the page.

If a file you can read doesn't contain the change the user describes, don't lead with it: note it
in a clause and still answer the pipeline question conditioned on the change being real.

## Declaring scope and opting in

**Extension (`*.info.yml`)** — the scope declaration lives under `neo:`, and declaring it is the
whole of the fix when an extension is missing from a build:

```yaml
neo:
  scope:
    - front
    - back
```

- A **scalar** `neo: true` in an info.yml is discarded: `NeoExtension::__construct()` resets a
  non-array `neo` value to `[]`. It registers the extension but declares no scope.
- With no scope declared the fallback is **type-dependent**: modules get `['front', 'back']`,
  **themes get `['front']` only** — a theme with no declaration is excluded from the `back` build
  entirely. The module README's "If a scope is not defined, theme will act on all scopes" is
  wrong; the code is the authority.
- Only **enabled** modules and **installed** themes are walked by the build.

**Library (`*.libraries.yml`)** — here `neo: true` *is* the correct opt-in. The library lists
`src/` entrypoints rather than `dist/` paths and inherits its extension's scope unless it declares
its own (`neo: {scope: back}`); at most one CSS and one JS file each. `neo: {import: true}` on a
CSS library instead folds that stylesheet into the scope's primary Tailwind stylesheet — the
library is then removed from the library set entirely and produces no compiled file of its own.

**Tailwind extension keys are read only from inside `neo:`** — `theme`, `base`, `components`,
`utilities`, `variants`, intersected against the contents of `neo:` by
`NeoExtension::getTailwindInfo()`. A key of any of those names at the **top level** of an info file
has no effect: no code reads it and no info file in this repo has one, despite the README's `THEME`
example. Only `utilities` has evidenced usage here (nested key carrying an `apply:`).

After editing any of these: `ddev drush cr`, then rebuild the scope.

## Costly, disruptive or overwriting — surface it, don't decide it

- **Stopping the dev server is not cheap.** When the bundler process for a dev run exits, the CLI
  immediately launches an unattended **production build of every scope**, then cleanup — which
  deletes the project's `.git/hooks/pre-commit` unconditionally (whatever wrote it, GrumPHP's
  included), deletes `_neo.lock`, and rewrites `neo_build.info` `versions.*` (surfacing on the
  next config export). Nobody needs to run `deploy` after Ctrl+C; it already ran.
- **The build target is never validated; the scope is.** An unknown scope exits with an error
  listing the valid ones. The target is compared only against the literal `prod`, so `production`,
  `PROD` or any typo takes the dev branch: the guard fires only for literal `prod`, so a typo
  walks past it, enables dev tracking (writing `_neo.lock` and the pre-commit hook), builds the
  named scope, then chains into a whole-project build plus cleanup. It does not error out. Only
  ever write `prod` or `dev`.
- **`drush neo:build:install`** re-aggregates every enabled module's `install/skills/*` into
  `.claude/skills/`, but also overwrites the tracked project-root `package.json`, `tsconfig.json`
  and `vite.config.ts` from module templates (local tuning lost) and edits `.ddev/config.yaml`.
  Never prescribe it without stating that.
- **Never substitute destruction for a build:** no deleting `dist/`, no removing `node_modules`,
  no `git checkout` of generated files, no blanket cache rebuild as the cure for a compile.

## Generated files, and the diff a build produces

**Never hand-edit:** `neo.json`, `neo.tsconfig.json`, `phpstan.neon`,
`web/themes/*/src/css/tailwind.neo.css` (banner: "Generated by Neo Build. Do NOT edit this file
directly."), anything under any `dist/`, and `.claude/skills/**` — copies of
`<module>/install/skills/<name>/`.

**Tracked, so a build makes a large committable diff:** both themes' `dist/` (~90 files each),
both generated `tailwind.neo.css`, `phpstan.neon`, `neo.tsconfig.json`. Untracked: `neo.json`
alone — `neo.tsconfig.json` is tracked because `.gitignore` spells the rule `/tsconfig.neo.json`,
name components transposed, so it never matches. A whole-project build also rewrites the
`neo_build.info` config object.

Each prepare run replaces `neo.tsconfig.json` wholesale with one scope's TypeScript entrypoints —
so a single-scope build drops the other scope's entries, and a whole-project build leaves
whichever scope was prepared last. Raise this only when the user is about to commit build output.

## Type checking

`ddev exec npx tsc --noEmit` — never `-p neo.tsconfig.json`. That generated file is an `include`
array and nothing else (root `tsconfig.json` holds the compiler options and `extends` it), so
aiming `tsc` at it runs on the language defaults and emits a wall of phantom errors. A production
build runs `vite build && tsc`; a type error at that stage does not mean output was not written.

## Command inventory (exact — near-misses misbehave here rather than being rejected)

Drush, all nine, primary name + alias; the module provides no others:

`neo:build` (`neo`) · `neo:build:status` (`neo-status`) · `neo:build:cc` (`neo-cc`) ·
`neo:build:scopes` (`neo-scopes`) · `neo:build:install` (`neo-install`) ·
`neo:build:dev:enable` (`neo-dev-enable`) · `neo:build:dev:disable` (`neo-dev-disable`) ·
`neo:build:dev:cleanup` (`neo-dev-cleanup`) · `neo:template` (`neo-template`)

npm scripts, all five: `start` · `deploy` · `build:front` · `build:back` · `preview`

This list is for checking a name you were given, not a menu to offer from. When a cache clear is
the prescription, `ddev drush cr` is the one to write.

To check any of this: `tools/neo-cli.cjs` (target/scope parsing, the guard, what Ctrl+C triggers),
`src/Commands/DrushCommands.php` (prepare, status, cleanup, install), `src/NeoExtension.php`
(scope fallbacks, Tailwind keys), `src/NeoBuild.php` + `neo_build.module` (the dev-vs-`dist`
decision), `tools/neo-vite-plugin.ts` (the watcher), all under
`web/modules/contrib/neo_build/`; and each scope's generated `tailwind.neo.css` for its globs. The
README is background and stale on the theme no-scope fallback, on `@tailwind base;` (this is
Tailwind v4), and on the top-level `theme:` example. Prefer the code.
