---
title: 'Islands'
description: Declare, mount, and render interactive PHP regions in CakePHP.
---

# Islands

An island is a PHP class that renders a part of a page and handles its updates
without a full page reload.

## Declaring an island

Extend `Interact\Island` and use the `Island` class suffix. Interact resolves
classes on demand through CakePHP's application/plugin class conventions.

```php
<?php
declare(strict_types=1);

namespace App\Island\Article;

use Interact\Island;

/**
 * Renders the article editor through its matching island template.
 */
class EditorIsland extends Island
{
}
```

Mount this class with `render('Article/Editor')`. It renders
`templates/island/Article/editor.php` without a registration attribute.
`render('article/editor')` selects the same class. Use names such as
`Commerce.Article/Editor` for loaded plugins and `Acme/Commerce.Article/Editor`
for vendor-namespaced plugins. See [Conventions](/guide/conventions).

## Template convention

The path follows the class beneath `Island`, preserving namespace folders and
underscoring the final class name after removing the `Island` suffix.
See [class and template paths](/guide/conventions#island-classes-and-templates).

## Plugin templates and overrides

Plugin islands use their owning plugin's templates. Applications override them
through CakePHP's `templates/plugin/PluginName/` directory. See
[plugin templates and overrides](/guide/conventions#plugin-templates-and-overrides).

## Render options

Use optional `#[Render]` from `Interact\Attribute` to select another template,
reuse an element, or change the generated root tag:

```php
#[Render(template: 'Articles/edit', tag: 'section')]
```

| Argument   | Default | Description                                             |
| ---------- | ------- | ------------------------------------------------------- |
| `template` | `null`  | Native template; inferred when both targets are omitted |
| `element`  | `null`  | Native CakePHP element                                  |
| `tag`      | `'div'` | Generated root element                                  |

Declare at most one of `template` and `element`. Allowed root tags are `div`,
`section`, `article`, `aside`, `main`, `nav`, `header`, `footer`, and `fieldset`.
Props, state, uploads, and actions retain their explicit declaration attributes.

## Mounting

Render an island from a normal CakePHP template:

```php
<?= $this->Interact->render('Article/Form') ?>
```

`InteractHelper::render()` returns the island's HTML. [Installation](/installation) configures the helper
and browser runtime. [Quickstart](/getting-started) contains a complete counter.

| Argument      | Default   | Description                                                           |
| ------------- | --------- | --------------------------------------------------------------------- |
| `alias`       | Required  | CakePHP-style island name                                             |
| `props`       | `[]`      | Values declared with `#[Prop]`                                        |
| `key`         | Alias     | Stable identity among siblings                                        |
| `urlPrefix`   | `null`    | Prefix for this instance's URL parameters                             |
| `loading`     | `'eager'` | Eager rendering, lazy visibility, deferred startup, or manual loading |
| `placeholder` | `null`    | Custom placeholder element for a deferred mount                       |

See [lazy loading](/guide/lazy-loading) for loading modes and placeholders.

### Helper options

| Option       | Default     | Description                                              |
| ------------ | ----------- | -------------------------------------------------------- |
| `autoScript` | `true`      | Queue the Composer bundle in the layout's script block   |
| `scope`      | `'default'` | Server-configured scope bound to snapshots               |
| `endpoint`   | `null`      | Named `interact:update` route, or an explicit routed URL |

See [Installation](/installation) for helper setup and
[custom endpoints](/security#custom-endpoints) for scoped routes.

## Passing initial values

Declare mount parameters with [`#[Prop]`](/guide/state#prop), then pass them
explicitly:

```php
<?= $this->Interact->render('Article/Editor', props: ['articleId' => 7]) ?>
```

Controller view variables are not automatically available inside an island.
Provide render data through properties or `viewData()`.

## Repeated islands

Give repeated instances unique stable keys:

```php
<?= $this->Interact->render('Counter', key: 'first') ?>
<?= $this->Interact->render('Counter', key: 'second') ?>
```

Use `urlPrefix` when repeated instances declare [URL state](/guide/url-state#independent-islands).

Keys default to the supplied island name. Each instance has independent state. See
[nested islands](/guide/nesting) for child identity and preservation.

## Templates and page islands

Embedded islands normally render their inferred island templates. A [page island](/guide/page-islands)
can render an action template and retain the surrounding layout.

Every update constructs a fresh PHP instance, restores its declared properties,
and renders its content. It does not rerun the original controller action.
See [Lifecycle](/guide/lifecycle) for request order and
[Rendering and helpers](/reference/rendering) for custom view configuration.

## Defer an embedded island

Use `render('ResourceCount', loading: 'lazy')` to load when visible, `defer` after startup, or `manual` on request. Eager rendering remains the default. See [lazy loading](/guide/lazy-loading) for placeholders and lifecycle rules.
