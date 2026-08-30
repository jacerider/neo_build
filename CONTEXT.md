# CONTEXT — neo_build

Terms specific to the Neo asset build: the Vite + Tailwind pipeline that compiles each
extension's assets per scope. One entry per term: what it IS, then the names not to use for it.

## Neo asset build (`neo_build`)

**Scope** — one partition of the asset build: exactly `front` (the public theme) or `back` (the
admin theme). Tailwind is compiled per scope, so a class is only real in the scope whose build
contained it. The set is **closed and defined in `neo_build`'s own code**; it was a
YAML-discovered plugin type until the `neo-build-scope-constant` spec, which found that no site
in nine had ever added one. _Avoid:_ "target" (that is dev vs prod), "group" (a retired
concept), and "scope" unqualified when `neo_alchemist` is also in view — there it means a
component's `config`/`field`/`entity` scope, an unrelated idea.

**Scope identity** — a scope's id **is** the machine name of the theme it compiles into: the
`front` scope builds into the `front` theme, `back` into `back`. Every site running `neo_build`
is built this way, and the dist root, `neo:template`, `neo_form`'s per-theme settings and the
active-scope derivation all depend on it. Stated as a rule from the `neo-build-scope-constant`
spec onward rather than left as a coincidence the code rediscovers in four places. _Avoid:_
"scope mapping" (there is nothing to map).

**Scope enum** — `Drupal\neo_build\Scope`, the backed enum that *is* the scope set: one case per
scope, carrying the scope's label, its description and its theme name. Every place that used to
ask the plugin manager, and every place that used to spell `['front', 'back']`, reads it. It
appears in signatures that carry exactly one scope; a scope that has round-tripped through an
info file, `neo.json` or Drupal state is still a string. _Avoid:_ "scope plugin", "scope
manager", "scope plugin type" — all three named the thing it replaced.

**Neo library** — a `*.libraries.yml` entry flagged `neo:` whose CSS/JS paths point at source
entrypoints; `neo_build` rewrites them to compiled `dist/` assets (or the dev server) at
render time. _Avoid:_ "Vite library".

**Neo extension** — a module or theme that takes part in the build: it declares a `neo:` key in
its info file or owns at least one Neo library. Only Neo extensions are tracked in the
compiled-versions record and (today) in the generated PHPStan paths. _Avoid:_ "Neo module".

**Entrypoint** — a single source CSS or TS file a Neo library points at; Vite compiles each one.

**Primary file** — the one CSS entrypoint in a scope that carries `@import "tailwindcss"`; its
directory receives the generated `tailwind.neo.css` and its extension's `dist/` is where the
scope's build lands. _Avoid:_ "main CSS", "theme CSS".

**Prepare** — the Drupal-side step (`drush neo:build <scope>`, alias `neo`) that collects
every scoped Neo extension, dispatches the build event, and writes the build's inputs —
`neo.json`, `neo.tsconfig.json`, `phpstan.neon` at the project root and `tailwind.neo.css`
beside the primary file. It compiles nothing; Vite does that afterwards. _Avoid:_ "build"
(the compile), "generate".

**Build event** — `NeoBuildEvent` (`new_neo_build`), dispatched once per prepare with the
collection and the scoped extensions; sibling Neo packages subscribe to add Tailwind theme
values, variants, sources, components and base styles. _Avoid:_ "prepare event".

**Inline event** — `NeoBuildInlineEvent` (`neo_build_inline`), dispatched once per scope when
the inline CSS files under `public://neo-build/` are regenerated; unrelated to prepare. It
carries the scope, and says so: `getScope()` returns a scope enum case. It was called
`getThemeName()` until the `neo-build-scope-constant` spec, which worked only because a scope id
and its theme's machine name are the same string. _Avoid:_ "the theme name" for what it
carries.

**Collection** — `NeoBuildCollection`, the object the build event hands to subscribers; holds
everything prepare has gathered (host/port/dev flags, Tailwind sources/imports/theme/base/
components/utilities/variants, Vite entrypoints, TS includes, PHPStan paths, stylelint globs).
_Avoid:_ "manifest" (that is Vite's `manifest.json`), "config".

**Dev mode / HMR** — the state in which the Vite dev server serves live source for one scope
and `dist/` is intentionally stale; tracked in Drupal state and by `_neo.lock` at the root.

**Prod build** — `npm run deploy` / `build:<scope>`: prepare, then Vite compiles to `dist/`
and the compiled-versions record is stamped. _Avoid:_ "release", "compile" on its own.

**Compiled-versions record** — `config/neo_build.info.yml`, committed and shared: the version
of each Neo extension at its last prod build, compared against the installed version on the
status report. _Avoid:_ "build info".

**Manifest** — Vite's `dist/manifest.json` for a theme, read at render time to map entrypoints
to hashed `dist/` files. _Avoid:_ using it for `neo.json`.

## Neo asset build — prepare internals (settled by the `neo-build-preparer` spec)

**Preparer** — the `neo_build.preparer` service that owns prepare: it gathers the scoped Neo
extensions into the collection, dispatches the build event, runs the generators and writes
the artifacts, then records the scope state and invalidates `exo_build:build`. The
`neo:build` Drush command is a shell around it. _Avoid:_ "builder", "build service" (that
name belongs to the render-time `neo_build` service).

**Prepare result** — what `prepare(scope)` returns: the artifacts written (by path) and the
notices and warnings gathered on the way (extension added, missing entrypoint skipped, no
primary file for the scope, retired Tailwind section declared). The command prints it; tests
assert on it. _Avoid:_ "log".

**Artifact** — one file prepare produces: `neo.json`, `neo.tsconfig.json`, `phpstan.neon`
(project root) or `tailwind.neo.css` (beside the primary file). _Avoid:_ "output file",
"config file".

**Generator** — the class that renders one artifact from the collection, read-only; one per
artifact. The CSS/JSON partition of the collection's Tailwind data (variables, components,
utilities, variants, imports and sources go to the CSS artifact; the remaining
theme keys, Vite entrypoints, stylelint globs and host/port flags go to `neo.json`) is a rule
the generators share, not a drain performed by one on behalf of the other. _Avoid:_
"serializer", "writer" (the preparer writes).

**Partition rule** — which collection data each artifact owns: CSS variables (theme keys
beginning `--`), components, utilities, variants, imports and sources → `tailwind.neo.css`;
the remaining theme keys, Vite entrypoints, stylelint globs, icon data and the host/port/dev/
root/scope flags → `neo.json`; TS includes → `neo.tsconfig.json`; analysed extensions →
`phpstan.neon`. Stated once and shared by the generators; formerly an ordering accident (the CSS
step emptied the collection before `neo.json` was serialised). _Avoid:_ "drain", "consume".

**Analysed extensions** — the set of extensions the generated `phpstan.neon` lists as
`paths`: every Neo extension (as before) plus every enabled module or theme whose info file
declares `package: Neo` (exact match), plus `modules/custom`.
`vendor/mglaman/phpstan-drupal/extension.neon` is included only when
`phpstan/extension-installer` is not installed. _Avoid:_ "stan paths".

**Exclusion rule** — within an analysed extension's directory, any nested extension whose
declared dependencies are not all installed on disk is listed under `excludePaths`, because
PHPStan cannot resolve the classes it extends and refuses to ignore that. Installed-but-disabled
nested extensions stay analysed. _Avoid:_ naming individual files; the rule is about
resolvability.

**`neo_build_entity_print`** — the optional submodule of `neo_build` that swaps `entity_print`'s
asset renderer so dev-mode prints pick up Neo's CSS-as-JS assets; depends on `entity_print`,
enabled by an update hook where `entity_print` is enabled, excluded from analysis elsewhere by
the exclusion rule. _Avoid:_ "the entity_print shim" in new writing (the thing it replaces).

## Neo asset build — the build CLI (settled by the `neo-build-exit-codes` spec)

**Build CLI** — `tools/neo-cli.cjs` in `neo_build`, the Node entry point behind `npm start`,
`npm run deploy`, `npm run build:<scope>` and `npm start test`. It runs no compilation itself;
it orders the build steps and owns the process exit status. _Avoid:_ "the build script",
"neo-cli" on its own in prose.

**Build step** — one child process the build CLI runs: the dev-mode toggle
(`drush neo-dev-enable|disable`), prepare (`drush neo <scope>`), the compile, the type check,
and build cleanup. Each has an exit status; the CLI decides what that status means.
_Avoid:_ "task", "stage".

**Compile** — the `vite build` half of a prod build step: Vite writes the scope's `dist/`.
_Avoid:_ "build" unqualified when the type check is also meant.

**Type check** — the `tsc` half of the same step, run after the compile against the root
`tsconfig.json` and the generated `neo.tsconfig.json`. It is the only type gate the stack has,
because esbuild strips types without checking them. A type check can fail after a compile has
already written `dist/`. _Avoid:_ "lint", "tsc" in prose.

**Fatal step** — a build step whose failure ends the run with a non-zero exit: the dev-mode
toggle, prepare, the compile and the type check. Build cleanup and the status read are not
fatal. _Avoid:_ "blocking step".

**Signal exit** — a build step ended by a signal (Ctrl-C on the dev server) rather than by a
status. Never a failure: quitting the dev server proceeds to the prod rebuild as it always has.
_Avoid:_ treating `status: null` as non-zero.

**Failure report** — what the CLI prints when a fatal step fails: the scope, the step by name,
and whether `dist/` was written. It replaces the unconditional "All builds completed
successfully" line, which from now on prints only when every scope succeeded. _Avoid:_ "error
message".

**Build cleanup** — `drush neo:build:dev:cleanup`: it unlocks (deletes `_neo.lock` and the
pre-commit hook) and performs the compiled-versions stamp. After this spec the two are
separable — cleanup always unlocks, and stamps only when the build succeeded. _Avoid:_ using
"cleanup" for the unlock alone.

**Compiled-versions stamp** — the write of `versions.*` into the compiled-versions record
(`config/neo_build.info.yml`, committed and shared with the team). It asserts "these versions
are what `dist/` was built from", so a failed build must not perform it. _Avoid:_ "version
bump" (that is the package's `.info.yml` release version).

## Neo asset build — the CSS artifact (settled by the `neo-build-tailwind-stylesheet` spec)

**Tailwind stylesheet** — the object that composes and renders the body of `tailwind.neo.css`
from what the partition rule gives it: sources, imports, `@theme` variables, component rules,
utilities and variants. The class is `Drupal\neo_build\Generator\TailwindStylesheet`. It is not
a general-purpose CSS builder and has exactly one caller, the CSS generator. _Avoid:_ "CSS
builder", "NeoCss" (the name it carried until the `neo-build-tailwind-stylesheet` spec).

**Emit order** — the fixed order of sections in the CSS artifact, in full: the header comment
and the `@plugin` line, the `@source` lines, the `@theme` block, the top-level rules, the
`@layer components` block, the `@custom-variant` lines, and the `@import` lines **last**. The
order is a decision, not an accident — see ADR 0001. _Avoid:_ "output order".

**Top-level rule** — a rule the stylesheet emits outside any `@layer`. Today every one is an
`@utility` block, which Tailwind 4 requires at top level; that is what the old "backward
compatibility" storage actually held. _Avoid:_ "unlayered rule", "backward-compatible rule".

**Theme override precedence** — the reason imports come last: a stylesheet pulled in by
`@import` is emitted after the generated `@theme` block, so a theme's own token wins over a
module's. Some sites depend on it today. _Avoid:_ "import order bug".

## Neo asset build — inline CSS and cache tags (settled by the `neo-build-dead-weight` spec)

**Build cache tag** — `neo_build:build`, invalidated by the preparer at the end of every
prepare, and `neo_build:build:dev`, carried by the inline event additionally while dev mode is
on. It is how "the build changed" reaches the inline CSS files: prepare invalidates, the inline
generator sees the tag among its monitored tags and regenerates after the response. Named
`exo_build:build` until this spec, after the predecessor suite that has no code left in the
module. _Avoid:_ "the exo tag", "build tag" (that is the compiled-versions record's business).

**Monitored tags** — the set of cache tags the inline generator watches, persisted in state at
each regeneration: the build cache tag plus every tag a sibling subscriber added to the inline
event. Because it is persisted, a site keeps watching the previous set until the next
regeneration — which is why renaming the build cache tag ships with an update hook that
regenerates. _Avoid:_ "watched tags", "inline tags" (that is the state key, not the concept).

## Neo asset build — render-time resolution (settled by the `neo-build-hidden-state` spec)

**Active scope** — the scope the *current request* renders in, derived per request and never
persisted. The rule reads scope identity first: if the active theme's machine name is a scope
id, that is the active scope; otherwise, if the active theme is the site's admin theme, `back`;
otherwise `front`. Settled in that form by the `neo-build-scope-constant` spec and recorded in
ADR 0002; the earlier default-then-admin ordering resolved to `front` on a site whose default
theme is also its admin theme, which some sites do. _Avoid:_
"current scope", "render scope", and above all "the scope" unqualified — that reads as the dev
scope.

**Dev scope** — the scope the last prepare ran for, held in Drupal state (`neo.build.scope`);
in dev mode it is the scope the Vite dev server is serving. `NeoBuild::getScope()` returns it,
and it decides only whether a library gets dev-server URLs. It has nothing to do with which
theme is rendering. _Avoid:_ "current scope", "prepared scope" in new writing.

**Dist root** — the directory a scope's compiled assets and its `manifest.json` land in: the
path of the theme that scope maps to. Prepare arrives at the same directory from the other
side, as the primary file's extension path, but does not record it; the resolver derives it.
_Avoid:_ "build output", and "dist path", which is the per-file result of a lookup.

**Manifest resolver** — the `neo_build.manifest_resolver` service that answers "which compiled
file serves this entrypoint, for this request": it derives the active scope, maps a scope to its
dist root, holds one manifest per theme for the request, and reports unresolved entrypoints.
`NeoBuild` injects it and keeps only library rewriting. _Avoid:_ "manifest loader", "manifest
service".

**Unresolved entrypoint** — an entrypoint absent from the manifest of a scope that *has* been
built: the scope compiled, but not this file, which means prepare has not run since it was
declared. Logged as a warning. Distinct from an entrypoint that cannot be found because the
scope has no manifest at all — that scope is simply unbuilt, and is silent. _Avoid:_ "missing
entrypoint", which is prepare's notice for a source file absent from disk.

**Dev server** — the `neo_build.dev_server` service: one owner for the Vite dev server's URL,
port and whether it is answering. It replaces three disagreeing answers — a raw TCP probe in
Drush, an HTTP probe in the build CLI, and a URL builder with the port hardcoded. Its URL is the
DDEV origin plus the configured port; DDEV is a **requirement of dev mode**, not a default with a
fallback behind it, so when the environment variable carrying that origin is absent the service
has no URL and dev mode refuses rather than composing one. _Avoid:_ "vite server"; "HMR server"
(dev mode / HMR is the state, this is the process).

**Dev-mode refusal** — what `drush neo:build:dev:enable` does when the DDEV origin variable is
missing: it names the variable and does not turn dev mode on, so the broken state is never
entered rather than diagnosed afterwards. `drush neo:build:status` reports the same cause in
place of the URL, and `buildServer()` in `neo-vite.cjs` refuses on the Node side for the same
reason. Settled by rejecting improvement candidate #8 — `neo_build` requires DDEV by documented
design, and no non-DDEV fallback exists to fall back to. _Avoid:_ "the DDEV check", which reads
as a portability shim; there is deliberately no portability path.

## Neo asset build — info.yml Tailwind data (settled by the `neo-build-flat-declarations` spec)

**Flat declaration rule** — the one shape Tailwind data may take in an extension's info file:
flat declarations whose property names are already kebab-case, plus the `apply:` key. A
property name carrying an uppercase letter, or a value that is an array, is refused. Anything
with a state, a pseudo-element or a nested selector is written as real CSS in an import
entrypoint instead. Stated as a rule because the info file and CSS were two ways to say the
same thing, and the YAML way was the one nobody could read. _Avoid:_ "camelCase support",
"nesting support" — both name capabilities that no longer exist rather than a rule.

**Import entrypoint** — a Neo library flagged `neo: { import: true }`, whose CSS file is
`@import`ed into the generated stylesheet rather than compiled as its own asset. It is where an
`@utility` block a theme writes by hand belongs, and where every `neo_theme` utility now lives.
Distinct from an ordinary entrypoint, which Vite compiles to its own `dist/` file. _Avoid:_
"utilities file" (a theme may have several), "import library".

**Declaration refusal** — what prepare does when an extension's info file breaks the flat
declaration rule: the stylesheet throws on the offending property, and the preparer — which
knows whose extension it is reading — names the extension, the selector and the key, and says
to write kebab-case or move the rule to an import entrypoint. It exists because `neo_build` and
`neo_theme` are versioned separately: a new `neo_build` beside an old `neo_base` used to emit
`backgroundColor:` and the literal `Array` into every site's form styling, silently, and report
success. _Avoid:_ "validation", "the camelCase check" — it refuses two shapes, not one.

## Neo asset build — component and utility data (settled by the `neo-build-entry-refusal` spec)

**Component data** — the collection's `components` section: a map of CSS selector → declarations
array, contributed by an extension's info file (`neo: components:`) or by a build-event
subscriber. The CSS artifact is its only consumer — `neo.json` emits `components` empty on
purpose — so what the CSS generator refuses is refused everywhere. **Utility data** is the same
shape under `utilities`, and becomes a top-level `@utility` block rather than a rule in
`@layer components`. _Avoid:_ "the components array" (that reads as one extension's block),
"Tailwind components" (Tailwind has no such concept in v4).

**Selector-key rule** — the one shape component and utility data may take: every entry is a
**string** key naming a CSS selector, mapped to an **array** of declarations. A key that names a
custom property (`--…`) is not a selector, and a value that is not an array is not a set of
declarations. Custom properties are perfectly legal *inside* a rule — `neo_color` emits fourteen
`--tw-prose-*` declarations inside one — but never in selector position, where they would emit a
bare declaration inside `@layer components`. _Avoid:_ "no CSS variables in components", which is
the opposite of true.

**Entry refusal** — what prepare does when component or utility data breaks the selector-key
rule: the CSS generator throws, naming the section, the key as given and which half of the rule
it broke, and prepare **fails**. It replaces an uncaught `TypeError` out of the stylesheet's
array-typed parameter, and it exists because the route it guards silently worked until the
`neo-build-tailwind-stylesheet` spec removed the branch that handled it. Distinct from
**declaration refusal**, which is about a property *inside* an entry; this one is about the entry
itself. _Avoid:_ "component validation" — it refuses three shapes at one gate, and does not
validate CSS.

## Neo asset build — retired Tailwind sections (settled by the `neo-build-base-key` spec)

**Tailwind section** — one of the keys an extension's `neo:` block may use to declare Tailwind
data: `theme`, `components`, `utilities` and `variants`. The list is closed and each section
has exactly one destination in the collection; the `neo:` block's other keys (`scope`, and
`group`, which `neo_base` declares and nothing reads) are not sections and are untouched by
this vocabulary. _Avoid:_ "Tailwind info", "the neo: block" — both name more than the four
sections.

**Retired Tailwind section** — a key that used to be a Tailwind section and now reaches
nothing. `base` is the only one, and the only one there is expected to be. Prepare names the
extension and the key in a notice and drops the declaration; it does not refuse the build.
_Avoid:_ "deprecated key" — nothing still honours it, so there is no transition period.

**Retirement over refusal** — why a retired section warns where a broken declaration throws.
The **Declaration refusal** and the **selector-key rule** both exist because honouring the
declaration would put something bad into a stylesheet; a retired section emits nothing whether
it is honoured or dropped, so the only thing wrong is the author's expectation. Prepare refuses
what would produce a bad build and warns about what merely reaches nothing. Note the limit: the
prepare result carries no severity, so a warning still prints under `Prepare Success`, which is
why warning is right only for the reaches-nothing case. _Avoid:_ treating the two as one policy.

