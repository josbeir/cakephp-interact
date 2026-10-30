---
title: 'Islands'
description: Declare, mount, and render interactive PHP regions in CakePHP.
---

# Islands

An island is a PHP class that renders a part of a page and handles its updates
without a full page reload.

[How it works](/how-it-works) shows how a hosting view, island classes, and island
templates connect, and what happens when the browser sends an update.

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

## Table access

The base `Island` includes CakePHP's `LocatorAwareTrait`. Use the inherited
`fetchTable()` to resolve a Table by alias or class name:

```php
$articles = $this->fetchTable('Articles');
```

Assign typed Table properties in [`initialize()`](/guide/lifecycle#initialize)
when several hooks need them. A no-argument `fetchTable()` requires a configured
protected `$defaultTable` property. Keep domain queries in Table finders and
authorize protected resource access before querying.

`getTableLocator()` uses the application's native Table locator by default.
`setTableLocator($locator)` selects a locator for that island instance, including
in tests. Table and locator references stay on the server; they are excluded
from transported state and browser-callable actions.

## Request access

`getRequest()` returns the current CakePHP `ServerRequest`, including its session
and middleware attributes:

```php
$language = $this->getRequest()->getSession()->read('Config.language', 'en');
```

The runtime assigns the request before `initialize()` on every fresh instance,
including loading placeholders and subscription renewals. It stays outside
transported state. During an update, this is the Interact endpoint's request;
`getData()` contains the transport envelope. Read bound field values from island
state instead.

For manually constructed islands, use `setRequest($request)` before hooks that
access the request. `getRequest()` throws `LogicException` when none is assigned.

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

`InteractHelper::render()` also accepts [class references](/guide/conventions#class-references),
such as `render(EditorIsland::class)`, and returns the island's HTML. [Installation](/installation) configures the helper
and browser runtime. [Quickstart](/getting-started) contains a complete counter.

| Argument      | Default       | Description                                                           |
| ------------- | ------------- | --------------------------------------------------------------------- |
| `alias`       | Required      | CakePHP-style island name or island class reference                   |
| `props`       | `[]`          | Values declared with `#[Prop]`                                        |
| `key`         | Resolved name | Stable identity among siblings                                        |
| `urlPrefix`   | `null`        | Prefix for this instance's URL parameters                             |
| `loading`     | `'eager'`     | Eager rendering, lazy visibility, deferred startup, or manual loading |
| `placeholder` | `null`        | Custom placeholder element for a deferred mount                       |
| `model`       | `null`        | Parent writable path or named mappings for modelable child values     |

See [lazy loading](/guide/lazy-loading) for loading modes and placeholders.
See [nested islands](/guide/nesting#modelable-child-inputs) for child model bindings.

### Helper options

See [helper configuration](/configuration#existing-helper-options) for defaults,
asset inclusion, endpoint, scope, and document metadata. Scoped routes are
covered in [custom endpoints](/security#custom-endpoints).

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
