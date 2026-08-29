# 0003 — info.yml Tailwind data is flat; state styling belongs in CSS

**Status:** accepted · **Date:** 2026-08-22
**Context:** `neo_build` — the **flat declaration rule** and the **declaration refusal** in prepare
**Issue:** jacerider/neo_build#10  ·  **Plan:** `neo-build-flat-declarations` on wps

**Decision.** An extension's `neo:` block may declare Tailwind data only as flat declarations with
kebab-case property names, plus the `apply:` key; prepare refuses an uppercase letter in a property
name, or an array value, naming the extension, the selector and the key. A state, a pseudo-element
or a nested selector is written as real CSS in an **import entrypoint** (`neo: { import: true }`).
`neo_theme` moves every info.yml `utilities:` block there; `neo_build` deletes the camelCase
converter, the nested-property processor, the nested-selector normaliser and the `formatRule()`
recursion, and declares a Composer conflict against pre-migration `neo_theme` releases.

**Why it needs recording.** The stylesheet accepted two spellings of one thing — `marginBottom`
became `margin-bottom`, an array value became a nested rule — Tailwind 3 JS-config habits in YAML,
while `neo_base/src/css/_utilities.css` already held forty-plus `@utility` blocks with nested
`&:hover` as plain CSS beside nine YAML utilities saying the same. Finding no site used either form,
the `neo-build-tailwind-stylesheet` plan ordered the machinery removed, and its byte gate rightly
refused: the output was clean because the converter cleaned it — thirteen properties would have
shipped as invalid camelCase, every nested selector as the literal string `Array`. That left
machinery alive on evidence in a gitignored `docs/`; the target existed and was the majority
spelling, so the cause could go. Prepare throws rather than warns because `neo_build` and
`neo_theme` are versioned separately with no dependency: Composer can pair a new `neo_build` with an
old `neo_base` (likeliest where `neo_theme` tracks another branch), and that pairing reported
success as every form control lost its styling. A build that cannot produce correct CSS should not
produce a build; the conflict guards first, the refusal covers a subscriber source.

**Rejected.**
- Pin the behaviour with characterisation tests and docblocks (improvement candidate #9 as filed)
  — the smaller change, but it keeps two ways to write one thing and makes the worse one permanent.
- Remove the camelCase conversion only, keep nesting — half the cruft, byte-identical output, but
  nesting has no YAML form worth defending and keeps the recursion, normaliser and array branch.
- A deprecation release that warns while still converting — textbook expand–contract, but for one
  maintainer, nine sites and `pkg update-all` it buys tracking, not safety the conflict lacks.

**Cost.** The emitted `tailwind.neo.css` changes shape — the moved utilities arrive via the trailing
`@import` block, not the top-level rules section — so the plan's proof moves down to the compiled
`dist/` CSS, where an ordering-only diff is acceptable and anything else is not. A module without a
CSS entrypoint can still declare flat utilities in its info file; that route is unchanged.
