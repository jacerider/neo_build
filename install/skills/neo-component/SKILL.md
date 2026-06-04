---
name: neo-component
description: Create and modify Drupal Single Directory Components (SDC) for the neo_alchemist module. Use when the user asks to build, add, edit, or scaffold a page-building component in web/themes/front/components — or when referencing terms like "Neo component", "Alchemist component", "SDC", or file patterns like *.component.yml and *.twig under the theme's components directory.
allowed-tools: Read, Write, Edit, Glob, Grep, Bash
---

# Creating Neo Alchemist Components

The site uses the `neo_alchemist` module (Drupal contrib) to provide page-building via Drupal Single Directory Components (SDC). Components live in [web/themes/front/components/](web/themes/front/components/) and can be composed into pages by editors.

## Directory layout

Every component is a folder. Required files:

```
web/themes/front/components/<machine_name>/
├── <machine_name>.component.yml   # Schema/prop definitions (REQUIRED)
├── <machine_name>.twig            # Render template (REQUIRED)
├── README.md                      # Editor/developer notes (optional)
├── <machine_name>.js              # Component-local JS (optional, auto-loaded)
├── <machine_name>.css             # Component-local CSS (optional, auto-loaded)
└── thumbnail.png                  # Preview image (optional)
```

The `machine_name` MUST match the folder and file names. Use snake_case (e.g. `cards_s1`, `media_text_s1`). The `_s1`/`_s2` suffix is the site convention for "style 1/2" variants.

## The `.component.yml` file

Every component yml starts with this boilerplate:

```yaml
$schema: 'https://git.drupalcode.org/project/drupal/-/raw/10.1.x/core/modules/sdc/src/metadata.schema.json'
name: 'Human Readable Name'
status: stable
description: 'One sentence for editors.'
neo: true   # REQUIRED — tells Alchemist this is a managed component
props:
  type: object
  properties:
    # props defined here
  required:
    # optional list of required prop keys
slots:
  # optional named slots
```

Key fields:
- **`neo: true`** — required flag. Without it the component is not picked up by Alchemist.
- **`status`** — `stable`, `beta`, `experimental`, or `deprecated`.
- **`libraryOverrides.dependencies`** — attach Drupal libraries (e.g. `neo/library.alpine` for Alpine.js).

## Prop types (Alchemist shapes)

Alchemist extends SDC with custom "shapes" — reusable prop definitions from [neo_alchemist.neo_component_prop_defs.yml](web/modules/contrib/neo_alchemist/neo_alchemist.neo_component_prop_defs.yml). Use these by name rather than raw JSON Schema:

### Content shapes
- `heading` — object with `supertitle`, `title`, `subtitle`, `size`, `anchor`. Always provide `examples` with the three text fields.
- `markup` — rich text / array. Use for prose descriptions.
- `string` — plain text.
- `image` — object `{src, alt, width, height}`. Render with `neo_image_style()` or `neo_image()`.
- `image-uri` — just an image URL.
- `file` — object `{src, title, name}` for downloadable files.
- `remote_video` — YouTube/Vimeo embed `{src, thumbnail, title}`.
- `icon` — icon machine name (rendered via `icon(name)` Twig function).
- `link` — button-style link `{uri, title, options, icon, target, access}`. Usually paired with a `button_style`.
- `url` — similar to link but for anchor-style links.
- `email`, `telephone`, `uri` — single-value types.
- `address` — postal address object.
- `menu` — array of menu items `{title, description, icon, url}`.
- `breadcrumb` — array of `{title, url}`.
- `slug` — anchor/slug string.
- `media` — Drupal media entity reference.

### Style shapes (applied as CSS classes via attributes)
- `scheme` — color scheme selector.
- `containment` — horizontal width (`xs|sm|md|lg|full`). Set `apply: true` to auto-add the class.
- `spacing` — vertical component spacing (`xs|sm|md|lg|xl|2xl|3xl`). Already has `apply: true` by default.
- `text_align` — `left|center|right`.
- `heading_size` — `xs|sm|md|lg|xl|2xl|3xl`. Attached automatically to the `heading` shape.
- `button_style` — solid/outline/text variants in base/primary/secondary/accent.
- `button_size` — `xs|sm|md|lg|xl|2xl|3xl`.

### Structural shapes
- `region` — a nested drop zone where editors can place more components (used for tabs, accordions, containers with children).
- `array` — a repeater. Pair with `items:` to define the per-row schema, and provide `examples:` with sample rows (use `TRUE` as a placeholder entry if the items have no required text fields).

### Inline custom `style` shapes
Define a per-component style selector inline:

```yaml
border_top:
  type: style
  title: 'Border Top'
  apply: false        # don't auto-inject; reference via .getValue() in twig
  examples: none
  styles:
    none:
      label: None
      value: border-t-0
    top:
      label: Top
      value: border-t
```

`apply: true` auto-adds the `value` as a class on the element. `apply: false` lets you read it in Twig with `name.getValue()` and branch logic yourself.

### `maxItems`
Set on an `array` prop to cap editor input (e.g. `maxItems: 1` for a single optional CTA).

## Slots

Slots are named regions in the Twig template that editors fill with other components (block-level composition, not prop data). Declare in yml:

```yaml
slots:
  content:
    title: Content
```

And render in Twig with `{% block content %}{% endblock %}`. See [web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/example_container/](web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/example_container/) for a working example.

> **Slot vs region prop:** Use `slots` for top-level composable content areas. Use a `region` prop inside an `array` when you have multiple repeating drop zones (e.g. each tab or accordion panel gets its own region).

## The `.twig` file

### Required patterns

```twig
{%
  set classes = [
    'container-content',   # or 'container-content my-component'
    'my-component',
  ]
%}
<div{{ attributes.addClass(classes) }}>
  ...
</div>
```

- Always output `{{ attributes }}` (or `attributes.addClass(...)`) on the root element — Alchemist injects classes from style props with `apply: true` here.
- Wrap everything in a single root element.
- Use `container-content` for standard width OR `container-content my-component` depending on whether spacing should collapse with neighbors.
- `my-component` adds the vertical spacing class resolved from the `spacing` prop.

### Rendering props

| Shape | Twig pattern |
|---|---|
| `heading` | `<div{{ heading.size }}>` then access `.supertitle`, `.title`, `.subtitle`, `.anchor` |
| `markup` | `{{ description }}` wrapped in `<div class="prose max-w-none">` |
| `image` | `{{ neo_image_style(img.src, {focal: {width: 1200, height: 575}}, img.alt) }}` or `neo_image()` for responsive |
| `icon` | `{{ icon(name) }}` — add modifiers: `|icon_class('text-3xl')`, `|icon_only`, `|icon_library('regular')` |
| `link` | `<a{{ item.button_style }} href="{{ neo_uri(item.link.uri, item.link.options) }}">{{ item.link.title }}</a>` |
| `url` | Same as link — check `item.link.access` for permission-gated links |
| `remote_video` | `{{ neo_oembed(video.src) }}` inline, or `{{ neo_modal(thumb, {video: src}, 'media') }}` |
| `region` | `{{ accordion.region }}` — auto-renders nested components |
| `style` (apply:false) | `{{ border_top.getValue() }}` or `.addClass()` |
| `array` | `{% for item in items %} ... {% endfor %}` |

### Preview-mode hooks

When the component has interactive state (tabs, accordions), expose event hooks to the Alchemist editor preview. These are no-ops at runtime:

```twig
<button
  {% if neoIsPreview %}
    data-event='{"group": "tabs"}'   {# grouped: only one visible at a time #}
    data-event='{"action": "toggle"}' {# toggle: independent show/hide #}
    data-event                        {# basic: just allow clicks in preview #}
  {% endif %}
>
```

### Alpine.js

Add `- neo/library.alpine` to `libraryOverrides.dependencies` in the yml. For the Collapse plugin, also `{{ attach_library('neo/library.alpine.collapse') }}` at the top of the twig. Use `x-data`, `x-show`, `x-collapse`, `x-cloak` as normal.

### Swiper (carousels)

Image carousels use the built-in `swiper()` Twig function — see [web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/image/image.twig](web/modules/contrib/neo_alchemist/modules/neo_alchemist_examples/components/image/image.twig) for the canonical pattern (`swiper.getWrapperAttributes()`, `getSlideAttributes()`, `getNavigationPrevAttributes()`, etc.).

## Workflow for a new component

1. **Pick a machine name** — snake_case, typically `<purpose>_s<n>` (e.g. `testimonial_s1`). Check it's not already taken in [web/themes/front/components/](web/themes/front/components/).
2. **Find the closest existing component** and read its yml + twig. Copy that pattern — don't invent from scratch.
3. **Create the folder** at `web/themes/front/components/<name>/`.
4. **Write `<name>.component.yml`** — always include `$schema`, `name`, `status: stable`, `neo: true`, and a `spacing` prop. Use existing shapes (`heading`, `markup`, `image`, etc.) rather than raw JSON Schema types.
5. **Provide `examples:`** for every prop — these populate the Alchemist editor's default values and the preview. Arrays with `region` or booleans can use `- TRUE` as placeholder rows.
6. **Write `<name>.twig`** — root div with `{{ attributes.addClass(classes) }}`, wrap optional sections in `{% if ... %}`, use `neo_uri()` for all URLs, `icon()` for icons, `neo_image_style()` for images.
7. **Test interactive elements** with `{% if neoIsPreview %}data-event...{% endif %}` so the editor preview remains clickable.
8. **Clear the cache** (`drush cr`) after adding a new component — SDC registration is cached.

## Common pitfalls

- **Forgetting `neo: true`** — component won't appear in Alchemist's picker.
- **Raw `{{ url }}` instead of `{{ neo_uri(link.uri, link.options) }}`** — breaks internal `internal:/` URIs.
- **Missing `examples`** — editor shows empty previews and broken defaults.
- **Not wrapping in `{% if prop %}`** — component renders empty scaffolding when editor leaves fields blank.
- **Using `heading.title` for the `<h2>`** but forgetting `<div{{ heading.size }}>` — heading size prop won't apply.
- **New style prop with `apply: true` but missing `examples`** — class won't be present on first render.
- **Placeholder image dimensions out of sync with the twig transform** — the `placehold.co/WxH.png` URL (and `width`/`height` fields) in the prop's `examples:` should match the dimensions produced by `neo_image_style()` / `neo_image()` in the twig. The right target depends on the size op (see [web/modules/contrib/neo_image/README.md](web/modules/contrib/neo_image/README.md)):
  - Fixed-output ops — `scaleCrop`, `crop`, `focal`, `exact`, and `auto` with both width+height: placeholder must be exactly `{width}x{height}`. E.g. `{scaleCrop: {width: 300, height: 200}}` → `placehold.co/300x200.png`, `width: 300, height: 200`.
  - Width-only ops — `scale`, `focalWidth`, and `auto` with only width (or only height): output keeps the source aspect, so pick a placeholder that matches the *intended display aspect* (e.g. a `scale: {width: 1200}` slot shown in a 4:3 container → `placehold.co/1200x900.png`).
  - Responsive `neo_image()` with multiple breakpoints: use the largest breakpoint's dimensions for the placeholder.
  - Items rendered via a shared include (e.g. `@front/includes/list_s1--items.html.twig` uses `scaleCrop: 75x75`): match the include's dimensions, not the wrapper component.
- **Clearing cache** — after editing `.component.yml`, run `drush cr` or the prop changes won't reflect.
