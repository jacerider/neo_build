# 0001 — The Tailwind stylesheet emits its `@import` lines last

**Status:** accepted · **Date:** 2026-08-22
**Context:** `neo_build` — the **emit order** of the `tailwind.neo.css` artifact
**Issue:** jacerider/neo_build#4  ·  **Plan:** `neo-build-tailwind-stylesheet` on wps

**Decision.** The generated `tailwind.neo.css` emits its `@import` lines at the end of the file,
after the sources, the `@theme` block, the top-level rules, the layered rules and the variants. The
code comment that claimed "they must be at the beginning" was wrong and now says the opposite. The
imports are not moved to the top, and no setting exists to move them.

**Why it needs recording.** The CSS specification requires `@import` to precede every rule except
`@charset` and `@layer` statements, so a reader who knows CSS will take the trailing block for a
bug and move it. The artifact is never served to a browser: Vite and Lightning CSS inline each
import at the position it appears, so the specification's rule buys nothing and the position is
free to mean something else. Last means **theme override precedence** — every imported stylesheet
is inlined after the generated `@theme` block, so a token a theme declares beats the same token
declared by a module's build-event subscriber. Across the seven sites running `neo_build`
(`mnair-shop`, `coss`, `cultivators`, `rhls`, `wps`, `nasrcc-oowl`, `jost`) — fourteen generated
stylesheets — two depend on it: `rhls/front` and `jost/front` declare `--text-color-default` both
in the generated `@theme` block and in an imported theme stylesheet, and `jost/front` also declares
`@utility page-title` in both. The theme's value wins today. The other twelve stylesheets have no
collision, so moving the block would go unnoticed until someone looked at those two sites.

**Rejected.**
- Move the imports to the top and repair the two collisions by renaming or rescoping the colliding
  declarations in the `rhls` and `jost` themes — the repair lands in two site repositories, and the
  next site to declare a token its modules also declare inherits the same trap with nothing to
  catch it.
- Move them to the top behind a setting that restores the old position — it adds a configuration
  surface to a class that has none, and a site needing it would not discover that until something
  looked wrong.

**Cost.** The artifact contradicts the CSS specification on purpose, and a comment can only say so
much to a reader who trusts the specification first. A unit test pins the emit order, imports
included, so a future "fix" fails loudly rather than shipping to thirty sites. If the position is
ever revisited, the collision scan above is the thing to re-run first — not the CSS specification.
