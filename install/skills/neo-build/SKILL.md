---
name: neo-build
description: Understand and drive the Neo asset build — the Vite + Tailwind + TypeScript pipeline that compiles theme/module assets per scope (front/back). Use when editing *.ts / src CSS / *.libraries.yml `neo:` entries or info.yml `neo:`/`theme:` keys, when a class or asset change "isn't showing up", when deciding whether to run `drush neo:build` / `npm start` / `npm run deploy` / `drush cr`, or when working with the Vite dev server, build scopes, or `.claude/skills` installation. NOT for authoring components (use neo-component) or module PHP (use neo-alchemist-dev).
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# The Neo asset build

`neo_build` is a [Vite](https://vitejs.dev) + [Tailwind](https://tailwindcss.com) + TypeScript pipeline for Drupal asset libraries. It lets a library point at **source** entrypoints (`src/*.ts`, `src/css/*.css`) and rewrites them to compiled `dist/` assets at render time via a manifest — or serves them live from a Vite dev server during development.

Full human reference: [web/modules/contrib/neo_build/README.md](web/modules/contrib/neo_build/README.md). This skill is the operational layer: *what to run after editing what, and why a change isn't showing.*

> Authoring a page-building component (`*.component.yml` / `*.twig`)? Use **neo-component**. Editing neo_alchemist PHP? Use **neo-alchemist-dev**. This skill is only about the asset build.

## Mental model

- **`neo: true` libraries** — in a `*.libraries.yml`, a library flagged `neo: true` (or `neo: {scope: front}`) lists `src/` entrypoints instead of `dist/`. The module swaps them for the compiled `dist/` assets (and pulls in their dependencies) using `manifest.json`. When the Vite **dev server** is up, it serves the live source instead.
- **Scopes** — the build is partitioned into scopes: **`front`** (frontend theme) and **`back`** (backend/admin theme). **Admin pages render with the `back` theme.** A theme declares its scopes in info.yml (`neo: { scope: [front, back] }`); no scope = all scopes. List them with `drush neo-scopes`.
- **Tailwind is scope-isolated** — `@tailwind base` only emits the classes aggregated **for that scope**. A utility used only in a `front` library will **not exist** in the `back` build's CSS, and vice versa. This is the #1 cause of "my class does nothing": the class is real but the owning scope wasn't rebuilt (or you used it in the other scope).
- **Two-step build** — `drush neo:build <scope>` (alias `neo`) regenerates `neo.json` (the manifest of entrypoints for that scope); Vite then compiles them to `dist/`. The npm CLI orchestrates both: it calls `drush neo <scope>` then runs Vite.
- **Generated** — `neo.json` is generated and git-ignored. **`neo.tsconfig.json` is generated but TRACKED**, so every build shows up as a diff. It is only the `include` list, and a single-scope build (`npm run build:front`) rewrites it for *that scope alone*, silently dropping the other scope's entries — run `npm run deploy` before committing it, or the back scope loses its files. `_neo.lock` is the dev-mode lock file.

## Step 0 — is the dev server running?

Before running **any** build, check `drush neo-status` (`--format=json` for scripting). The `status` field is the verdict:

- **DEV** — a dev server is live. Changes in its scope are already served via HMR: src TS/CSS, `.twig`, `.component.yml`, `.php`, `.info.yml` and `.libraries.yml` edits are all watched, with cache clears and `neo.json` regeneration handled for you. Run **nothing**. Changes in the *other* scope still serve stale `dist/` — but a prod build would disconnect the dev session, so coordinate with the user instead of forcing one (Ctrl+C on their watcher terminal auto-runs a full prod build of all scopes).
- **PROD** — no dev server. Build normally: `npm run build:front` / `npm run build:back` for one scope, `npm run deploy` for all.
- **STALE** — dev state is on but the server died without cleanup. `npm run deploy` restores `dist/` assets.
- **ORPHANED** — a server is answering but Drupal isn't using it. Restart `npm start` or stop the server.

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

⚠ **`drush neo:build` is NOT the build.** It regenerates `neo.json` and stops, and its last line
is `Prepare Success` — so it looks like a completed compile, `drush cr` afterwards looks like the
finishing step, and the page still serves the old `dist/`. The tell is the mtime on
`web/themes/<theme>/dist/front.css`: unchanged means nothing compiled. Only the npm commands
below write `dist/`.

- `npm start` — interactive: pick a **scope** and **dev vs prod**. Dev launches the Vite **HMR dev server** (port 5173, exposed via DDEV); prod builds that scope to `dist/`. Non-interactive: `npm start -- <prod|dev> <scope>`.
- `npm run build:front` / `npm run build:back` — non-interactive prod build of **one** scope. Prefer these over `deploy` when you know the scope.
- `npm run deploy` — `npm start -all`: prod-build **every** scope. This is the "make it real / commit-ready" build.
- `npm run preview` — `vite preview`.

All prod builds are refused while a dev server is answering (see Step 0); `--force` overrides.

drush (manifest + lifecycle):

- `drush neo:build:status` / `neo-status` — dev mode, dev scope, and a live probe of the dev server (`--format=json` for scripting). **Run this first.**
- `drush neo:build [scope]` / `neo` — regenerate `neo.json` for a scope (default `front`). Lower-level; `npm start` calls this for you.
- `drush neo:build:cc` / `neo-cc` — clear just the twig/render/page caches (lighter than full `drush cr`).
- `drush neo:build:scopes` / `neo-scopes` — list scopes (`--format=json` for scripting).
- `drush neo:build:install` / `neo-install` — (re)install `package.json` / `tsconfig.json` / `vite.config.ts`, add the DDEV vite port, **and aggregate every enabled module's `install/skills/*` into `.claude/skills/`**.
- `drush neo:build:dev:enable` / `neo-dev-enable` — turn on dev tracking (installs a git pre-commit hook + writes `_neo.lock` so dev/HMR state isn't committed).
- `drush neo:build:dev:disable` / `neo-dev-disable` — turn it off.
- `drush neo:build:dev:cleanup` / `neo-dev-cleanup` — remove the hook + lock and refresh compiled version info.
- `drush neo:template` / `neo-template` — scaffold a `*.html.twig` template into a theme from `neo_base`'s `.example` templates.

## Dev server vs `dist/`

`npm start` in **dev** mode runs Vite with HMR: edits to `src` TS/CSS (and watched Twig/yml) reload instantly. While the dev server is reachable on `:5173`, the module serves **live source** and **`dist/` is intentionally stale — don't rebuild it or trust its contents in dev.** The switch is cache-gated: after starting/stopping the server, `drush cr` so the library definitions re-resolve to server-vs-dist.

For committed/production assets, run `npm run deploy` (builds all scopes to `dist/`). `dist/*.css` is the source of truth in prod; in dev it is not.

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

- **Class is real but does nothing** — Tailwind scope isolation. The class wasn't compiled into the scope actually rendering the page (or you added it to `front` but need it in `back`, or vice versa). Rebuild the owning scope; when in doubt (and no dev server is up) `npm run deploy`.
- **Build refused: "a Neo dev server is already running"** — not an error. The watcher already serves your change via HMR; at most `drush cr` if something cached. Only a cross-scope change needs a build, and that means stopping the dev session first — ask the user rather than passing `--force`.
- **Admin-side change didn't apply** — admin uses the **back** theme; you probably only rebuilt `front`.
- **Edited `dist/` in dev and saw no effect** — the dev server supersedes `dist/`; stale `dist/` in dev is normal. Change `src/`, not `dist/`.
- **`.libraries.yml` `neo:`/entrypoint change ignored** — library definitions are cached; `drush cr` (or `neo-cc`) before rebuilding.
- **New module skill not appearing** — skills only land in `.claude/skills/` when `drush neo:build:install` runs; the source lives in `<module>/install/skills/<name>/SKILL.md`, never edited directly in `.claude/skills` (that's the generated copy).
- **Committed `neo.json` / dev lock** — `neo.json` is generated and git-ignored; `neo.tsconfig.json` is generated but **tracked** (run `npm run deploy` before committing it so it carries every scope); `_neo.lock` means dev mode is on. Use `neo-dev-cleanup` before a production build/commit.
