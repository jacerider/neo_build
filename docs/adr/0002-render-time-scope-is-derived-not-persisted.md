# 0002 — The active scope is derived per request from the active theme, never persisted by prepare

**Status:** accepted · **Date:** 2026-08-22
**Context:** `neo_build` — the **active scope** and **dist root** the **manifest resolver** derives
**Issue:** jacerider/neo_build#7

**Decision.** The active scope — the scope a request renders in, which decides whose
`dist/manifest.json` resolves its entrypoints — is derived per request and never written down. The
rule reads **scope identity** first: if the active theme's machine name is a scope id, that is the
active scope; otherwise `back` if the active theme is the site's admin theme; otherwise `front`.
Prepare still finds the same directory as the **primary file**'s extension path and records nothing.

**Why it needs recording.** Prepare already knows the answer: it finds the CSS entrypoint that
imports `tailwindcss`, sets its extension's path as the primary root — the dist root itself —
writes it into `neo.json` and throws it away (`neo.json` holds one scope, and is gitignored). Two
code paths, because the questions differ: prepare asks which extension owns the Tailwind entry, a
fact about source files; render asks which scope this request is in, a fact about the current
theme that prepare cannot know without a request. Step order matters too: reading `system.theme`
alone (default `front`, admin `back`, else `front`) sent a theme both default and admin to `front`,
and two of nine sites set both to `back`. Fixed before the plan
was ticketed by `neo-build-scope-constant`: step 1 handles it by construction, and step 2 keeps a
non-Neo admin theme such as Claro right, where identity alone would not.

**Rejected.**
- Persist a scope → dist-root map in state, merged by each prepare — a cache of a derivable fact
  with a staleness story: no map after an upgrade without re-preparing, a single-scope prepare must
  merge or erase the other, and correctness rides on build order on a machine that is not serving.
  Likely to win later only if a site owns its primary file outside the theme its scope is named for.
- Persist it in the compiled-versions record (`config/neo_build.info.yml`) — same staleness, and as
  exported config every prepare on every developer machine dirties a shared file with a build fact.
- Keep selecting by active theme, as before — the defect itself: a library built into one scope and
  rendered in the other misses the lookup and ships its raw source path; latent here, not absent.
- Identity only, steps 1 and 3 — set aside in the scope-constant interview; left if step 2 is cut.

**Cost.** The derivation assumes a scope's primary file lives in the theme the scope is named after:
true on all nine sites, each with exactly `front` and `back`, but not guaranteed. Tailwind base CSS
in a module or base theme would derive a dist root with no manifest; those libraries pass through
unrewritten and the unbuilt-scope path stays silent, so the failure is "assets do not resolve", not
"wrong assets resolve". Step 2 has no test site behind it, so it is an allowance, not a measured
requirement. A third scope needs a theme named after it, and nothing else.
