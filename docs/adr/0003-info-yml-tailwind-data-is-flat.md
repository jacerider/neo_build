# 3. info.yml Tailwind data is flat; state styling belongs in CSS

**Date:** 2026-08-22
**Status:** accepted
**Context:** the `neo-build-flat-declarations` spec, from improvement candidate #9

## Decision

An extension's `neo:` block may declare Tailwind data only as **flat declarations with
kebab-case property names**, plus the `apply:` key. A property name carrying an uppercase
letter, or a value that is an array, is refused by prepare with a message naming the
extension, the selector and the key. Anything needing a state, a pseudo-element or a nested
selector is written as real CSS in an **import entrypoint** — a Neo library flagged
`neo: { import: true }`, whose file is `@import`ed into the generated stylesheet.

`neo_theme` moves every one of its info.yml `utilities:` blocks into import entrypoints, so no
`neo_theme` info file declares Tailwind data at all. `neo_build` deletes the camelCase
converter, the nested-property processor, the nested-selector normaliser and the recursion in
`formatRule()`, and declares a Composer conflict against the `neo_theme` releases that predate
the migration.

## Why

The stylesheet accepted two spellings of the same thing. `marginBottom` became
`margin-bottom`, and an array-valued key became a nested rule — conveniences carried over from
the Tailwind 3 JavaScript config era, expressed in YAML, where neither has any reason to
exist. The alternative was never hypothetical: `neo_base/src/css/_utilities.css` already held
**forty-plus `@utility` blocks with nested `&:hover` written as plain CSS**, next door to the
nine utilities that said the same thing in YAML.

The `neo-build-tailwind-stylesheet` plan ordered this machinery removed on the finding that no
site used either form, and its byte gate refused the removal: the *output* was clean precisely
because the converter cleaned it, and thirteen properties would have shipped as invalid
camelCase with every nested selector collapsed to the literal string `Array`. That refusal was
correct. What it left behind was worse than either outcome — machinery that survived on
evidence recorded in a `docs/` directory this site gitignores, on one developer's machine,
with nothing in the package saying why.

So the choice was between recording the evidence and removing the cause. Removing it is
available because the migration target already exists and is already the majority spelling.

## The refusal, and why it is fatal

`neo_build` and `neo_theme` are separately versioned packages with no dependency between them,
so Composer can pair a new `neo_build` with an old `neo_base` — most likely on the one site
whose `neo_theme` checkout tracks a different branch from the rest. Under the old behaviour
that pairing is silent: prepare reports success and every form control on the site loses its
styling. Prepare therefore **throws** rather than warning: a build that cannot produce correct
CSS should not produce a build. The Composer conflict is the earlier of the two guards, and
the runtime refusal covers the case where a build event subscriber, not an info file, is the
source.

## Alternatives rejected

- **Pin the behaviour with characterisation tests and document it in docblocks.** The
  candidate as filed, and the smaller change. It preserves two ways to write one thing forever
  and makes the worse way permanent by testing it.
- **Remove the camelCase conversion only, keep nesting.** Half the cruft for a fraction of the
  risk, and byte-identical output. Rejected because nesting is the half with no expression in
  YAML worth defending — and keeping it keeps the recursion, the normaliser and the array
  branch that make the class hard to read.
- **A deprecation release that warns while still converting.** Textbook expand–contract. For
  one maintainer, nine sites and `pkg update-all`, it buys a tracking obligation rather than
  safety the Composer conflict does not already provide.

## Consequences

The emitted `tailwind.neo.css` changes shape — the moved utilities arrive via the trailing
`@import` block instead of the top-level rules section — so the plan's proof moves down a
level to the compiled `dist/` CSS, where an ordering-only diff is acceptable and anything else
is not. A module without a CSS entrypoint of its own can still declare flat utilities in its
info file; that route is unchanged and neither deprecated nor removed.
