---
title: x-interact:asset
description: Track versioned head assets and explicitly retain shared styles during enhanced navigation.
---

# x-interact:asset

`x-interact:asset` controls external asset compatibility and shared style retention during [enhanced navigation](/directives/navigate). Put it directly on elements in `<head>`; no island or `x-data` is required.

```html
<script src="/js/app.a1b2.js" x-interact:asset="reload"></script>
<link
  id="editor-theme"
  rel="stylesheet"
  href="/css/editor.css"
  x-interact:asset="persist"
/>
```

The value is a literal `reload` or `persist`, with no expressions or modifiers. It follows the configured Alpine prefix.

## Reload on asset changes

Mark versioned application scripts or stylesheets with `reload`:

```html
<link rel="stylesheet" href="/css/app.a1b2.css" x-interact:asset="reload" />
<script src="/js/app.a1b2.js" x-interact:asset="reload" defer></script>
```

Interact compares the ordered declarations before loading destination assets. Adding, removing, reordering, or changing a tracked declaration requires a native visit. Comparison includes normalized full URLs, hashed filenames, query strings, and attributes such as integrity, media, crossorigin, and type. Attribute order does not matter. Compatible CSP nonce rotation does not cause a reload.

Declare shared tracked assets consistently in the layout. The built-in runtime fingerprint is automatic; application-owned bundles can use this directive or [`Interact.navigationVersion`](/configuration#navigation-compatibility).

## Persist shared styles

Unmarked page styles are removed when absent from the destination. Retain a shared stylesheet or inline style explicitly:

```html
<style id="editor-accent" x-interact:asset="persist">
  .editor {
    --accent: rebeccapurple;
  }
</style>
```

Persistent styles require a stable, unique, nonempty native `id`. Omitted persistent styles appear before the destination's declarations, so destination styles can override them. If the id reappears, the destination supplies its attributes, content, policy, and position. Removing `persist` from the returning declaration restores ordinary page ownership.

Persisted styles can affect later pages; scope selectors to their widget or region. This policy retains CSS only. Use [x-interact:persist](/directives/persist) for layout DOM and island clients.

## Supported elements

| Value     | Elements                                        | Additional requirement                                  |
| --------- | ----------------------------------------------- | ------------------------------------------------------- |
| `reload`  | External `<script src>` and stylesheet `<link>` | Consistent ordered declarations across compatible pages |
| `persist` | Stylesheet `<link>` and `<style>`               | Unique nonempty `id`                                    |

Invalid or ambiguous head policies require native navigation before head changes or asset loading. Scripts execute once per document; this directive does not unload their side effects or enable executable inline scripts. See [head elements and scripts](/directives/navigate#head-elements-and-scripts) for default merging, loading, and CSP behavior.
