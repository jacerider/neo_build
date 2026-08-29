# 0001 — The Tailwind stylesheet emits `@import` last

**Status:** accepted
**Date:** 2026-08-22
**Context:** `neo_build`, the `tailwind.neo.css` artifact
**Plan:** `docs/plans/neo-build-tailwind-stylesheet/`

## Decision

The generated `tailwind.neo.css` emits its `@import` lines **at the end of the file**, after the
sources, the `@theme` block, the top-level rules, the layered rules and the variants. The
stylesheet's own code comment used to claim the opposite — "they must be at the beginning" —
while the code appended them last. The comment was wrong; the code is right, and now says so.

## Why this is surprising

The CSS specification requires `@import` to precede every rule except `@charset` and `@layer`
statements, so a reader who knows CSS will read the trailing block as a bug and move it. A
reader who checks the code comment will be told the same thing. The artifact is never served to
a browser, though — Vite and Lightning CSS inline each import at the position it appears — so
the specification's rule buys nothing here, and the position is free to mean something else.

## What it means instead

Last means **theme override precedence**. Every `@import`ed stylesheet is inlined after the
generated `@theme` block, so a token a theme declares beats the same token declared by a module's
build-event subscriber. Moving the block to the top silently inverts that.

Measured across the seven sites that run `neo_build` (`mnair-shop`, `coss`, `cultivators`,
`rhls`, `wps`, `nasrcc-oowl`, `jost`) — fourteen generated stylesheets — two sites depend on it:

- `rhls/front` and `jost/front` both declare `--text-color-default` in the generated `@theme`
  block *and* in an imported theme stylesheet. The theme's value wins today.
- `jost/front` additionally declares `@utility page-title` in both. The theme's wins today.

The other twelve stylesheets have no collision at all, which is why the inversion would go
unnoticed until someone looked at those two sites.

## Alternatives considered

**Move the imports to the top and repair the two collisions** by renaming or rescoping the
colliding declarations in the `rhls` and `jost` themes. Rejected: the repair lands in two site
repositories, and the next site to declare a token its modules also declare inherits the same
trap with nothing to catch it.

**Move them to the top behind a setting** that restores the old position. Rejected: it adds a
configuration surface to a class that has none, and a site needing it would not discover that
until something looked wrong.

## Consequences

A unit test pins the emit order, imports included, so a future "fix" fails loudly rather than
shipping to thirty sites. If the position is ever revisited, the collision scan above is the
thing to re-run first — not the CSS specification.
