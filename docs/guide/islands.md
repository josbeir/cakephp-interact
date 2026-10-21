---
title: 'Islands'
description: Declare, mount, and render interactive PHP regions in CakePHP.
---

# Islands

An island is a PHP class that renders a part of a page and handles its updates
without a full page reload.

## Declaring an island

Extend `Interact\Island` and add `#[AsIsland]`. Import declaration attributes
from `Interact\Attribute`.

```php
/**
 * Renders and updates an article form.
 */
#[AsIsland('article-form', element: 'Interact/article_form')]
class ArticleForm extends Island
{
}
```

The alias identifies the island when mounting it. CakePHP's Attribute Resolver
discovers application islands in `src/Island/`.

| Argument   | Default  | Description                |
| ---------- | -------- | -------------------------- |
| `name`     | Required | Alias passed to `render()` |
| `element`  | `null`   | Native CakePHP element     |
| `template` | `null`   | Native CakePHP template    |
| `tag`      | `'div'`  | Generated root element     |

Declare exactly one of `element` or `template`. Aliases start with a lowercase
letter and contain lowercase letters, digits, `_`, `.`, or `-`.
Allowed root tags are `div`, `section`, `article`, `aside`, `main`, `nav`,
`header`, `footer`, and `fieldset`.

## Mounting

Render an island from a normal CakePHP template:

```php
<?= $this->Interact->render('article-form') ?>
```

`InteractHelper::render()` returns the island's HTML. [Installation](/installation) configures the helper
and browser runtime. [Quickstart](/getting-started) contains a complete counter.

| Argument      | Default   | Description                                                           |
| ------------- | --------- | --------------------------------------------------------------------- |
| `alias`       | Required  | Registered island alias                                               |
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
<?= $this->Interact->render('article-editor', props: ['articleId' => 7]) ?>
```

Controller view variables are not automatically available inside an island.
Provide render data through properties or `viewData()`.

## Repeated islands

Give repeated instances unique stable keys:

```php
<?= $this->Interact->render('counter', key: 'first') ?>
<?= $this->Interact->render('counter', key: 'second') ?>
```

Use `urlPrefix` when repeated instances declare [URL state](/guide/url-state#independent-islands).

Keys default to the alias. Each instance has independent state. See
[nested islands](/guide/nesting) for child identity and preservation.

## Templates and page islands

Embedded islands normally render elements. A [page island](/guide/page-islands)
can render an action template and retain the surrounding layout.

Every update constructs a fresh PHP instance, restores its declared properties,
and renders its content. It does not rerun the original controller action.
See [Lifecycle](/guide/lifecycle) for request order and
[Rendering and helpers](/reference/rendering) for custom view configuration.

## Defer an embedded island

Use `render('resource-count', loading: 'lazy')` to load when visible, `defer` after startup, or `manual` on request. Eager rendering remains the default. See [lazy loading](/guide/lazy-loading) for placeholders and lifecycle rules.
