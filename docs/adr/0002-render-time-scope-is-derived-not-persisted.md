# 0002 — Render-time scope is derived from `system.theme`, not persisted by prepare

**Status:** accepted
**Date:** 2026-08-22
**Amended:** 2026-08-22 by `docs/plans/neo-build-scope-constant/` — the derivation rule below
**Context:** `neo_build`, the manifest resolver and the render-time library rewrite
**Plan:** `docs/plans/neo-build-hidden-state/`

## Decision

The **active scope** — the scope a request renders in, which decides whose `dist/manifest.json`
resolves that request's entrypoints — is **derived per request**, and nothing about it is written
down. Prepare continues to discover the same directory from the other side, as the primary file's
extension path, and continues not to record it.

The rule reads **scope identity** first:

1. If the active theme's machine name is a scope id, that is the active scope.
2. Otherwise, if the active theme is the site's admin theme, `back`.
3. Otherwise, `front`.

Scope identity — a scope's id is the machine name of the theme it compiles into — is what makes
step 1 sound, and it is stated as a rule by the `neo-build-scope-constant` spec rather than left
implicit.

**This replaces the rule this ADR originally recorded**, which read `system.theme` alone: `front`
for the default theme, `back` for the admin theme, `front` otherwise. That ordering resolved a
theme that is *both* default and admin to `front`. Two of the nine sites running `neo_build` —
`carpenters-vr` and `nasrcc-oowl` — set `default: back` and `admin: back`, so every request on
those sites renders in `back` and would have resolved against `themes/front/dist/manifest.json`.
The defect was caught before the plan implementing this ADR was ticketed; no site ever ran it.
Step 1 fixes that case by construction, and step 2 keeps the answer right for a site whose admin
theme is a non-Neo theme such as Claro, where the old rule was already correct and identity alone
would not be.

## Why this is surprising

Prepare already knows the answer. It walks the scope's Neo libraries, finds the CSS entrypoint
that imports `tailwindcss`, and sets that extension's path as the scope's primary root — which is
exactly the **dist root** the resolver needs. It then writes that value into `neo.json` and
throws the knowledge away, because `neo.json` holds one scope at a time and is gitignored per
site. A reader who follows prepare will find the value computed, serialised, and then recomputed
by different means at render time, and will reasonably ask why the two are not the same code
path.

They are not the same code path because they answer different questions. Prepare asks "which
extension owns this scope's Tailwind entry?" — a fact about source files. Render asks "which
scope is this request in?" — a fact about the current theme, which prepare cannot know because
prepare has no request.

## What it costs

The derivation assumes the scope's primary file lives in the theme the scope is named after.
That holds on this site — front resolves to `themes/front`, back to `themes/back`, matching the
primary roots prepare discovers exactly — and on all nine `neo_build` sites, every one of which
has exactly the themes `front` and `back`. The model does not guarantee it: a site could put the
Tailwind base CSS in a module or a base theme, and the derived dist root would then be a theme
with no manifest of its own. That site's libraries pass through
unrewritten rather than resolving wrongly, and the unbuilt-scope path stays silent, so the failure
mode is "assets do not resolve", not "wrong assets resolve".

## Alternatives considered

**Persist a scope → dist-root map in state**, merged by each prepare. Rejected: it is a cache of
something derivable, and every cache of a derivable fact acquires a staleness story. A site that
upgrades without re-preparing has no map; a single-scope prepare must merge rather than overwrite
or it erases the other scope; and the map's correctness then depends on build ordering on a
machine that is not the one serving the request.

**Persist it in the compiled-versions record** (`config/neo_build.info.yml`), which is committed
and shared. Rejected on the same staleness grounds plus a worse one: it is exported config, so
every prepare on every developer machine would dirty a file the team shares, for a build fact
that is not site configuration.

**Keep selecting by active theme, as before this plan.** Rejected because it is the defect: a
library built into only one scope, rendered in the other, misses the lookup and ships its raw
source path. Latent on this site — the only per-scope-unique manifest entries are the themes' own
CSS and JS, which never render in the other scope — but latent is not absent.

## Consequences

Adding a third scope needs a theme named after it, and nothing else: scope identity answers
"which theme" by construction, and the derivation's step 1 picks it up for free. If a site ever
does own its primary file outside the theme its scope is named after, the fix is the persisted
map rejected above, and this ADR is the record of what that costs.

Step 2 is the part with no test site behind it — no `neo_build` site uses a non-Neo admin theme
today — so it is a deliberate allowance rather than a measured requirement. If it ever becomes
dead weight, deleting it leaves steps 1 and 3, which is the "identity only" rule this spec's
interview considered and set aside.
