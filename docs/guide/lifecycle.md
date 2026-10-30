---
title: 'Lifecycle'
description: Look up island hooks and initial-render and update order.
---

# Lifecycle

Interact constructs a fresh island for every request. Declared props and state
are restored between requests; services and protected properties remain local
to the current instance.

## Initial render

1. Resolve the island name and construct the island through CakePHP's container.
2. Call `initialize()` for request-local setup.
3. Assign mount props and decode declared URL-bound state.
4. Call `authorize(IslandContext $context)`.
5. Call `mount()`.
6. Call `viewData()` and render its native element or template.

## Update

1. Verify the signed snapshot, session, scope, and expiry.
2. Construct a fresh island, call `initialize()`, and restore its declared properties.
3. Verify any parent-input capability, apply declared reactive inputs and browser edits, and validate action argument types.
4. Call `authorize()`.
5. Call `updated()` for changed fields, then the requested action.
6. Return a requested redirect, or return accepted state, errors, events, and any download request. Call `viewData()` and render unless a successful action skips rendering.

The original controller action, layout, and `mount()` do not run again.
Authorization can observe accepted edits and runs before `updated()`,
actions, and rendering.

## initialize()

```php
public function initialize(): void
```

Configure request-local dependencies, such as Table properties. The runtime calls
this hook once after construction, including when a subclass defines its own
constructor. Constructor-injected services are already available; declared props,
URL values, and snapshot state have not been assigned yet. Declared
[`PaginationState`](/guide/pagination) objects already contain their attribute
defaults, just as ordinary typed properties contain their PHP defaults.

```php
namespace App\Island;

use App\Model\Table\ArticlesTable;
use Interact\Island;

/**
 * Makes the application's Articles Table available to authorized island behavior.
 */
class ArticleListIsland extends Island
{
    protected ArticlesTable $articles;

    /**
     * Assign the Table for this request without transporting it to the browser.
     */
    public function initialize(): void
    {
        $this->articles = $this->fetchTable(ArticlesTable::class);
    }
}
```

This example assumes an application `Articles` Table and the conventional
`templates/island/article_list.php` template. The protected Table is available to
`authorize()`, `mount()`, actions, and `viewData()` and stays out of snapshots.
The base `Island` includes CakePHP's `LocatorAwareTrait`, so `fetchTable()` is
inherited without adding the trait to each island. See [Table access](/guide/islands#table-access)
and the complete [search example](/demo#search).

`initialize()` runs before authorization, including on denied requests and
loading placeholders. Keep it limited to instance configuration. Load protected
resources and initialize editable drafts in authorized hooks such as `mount()`;
use `viewData()` for computed rendering data. Overriding an application base
island's setup should call `parent::initialize()` when that setup is needed.
The hook cannot be exposed as a browser action.

## authorize()

```php
public function authorize(IslandContext $context): void
```

Override this hook for current resource access checks on both mounts and updates.
Import `Interact\IslandContext`. The request is available as `$context->request`.
Throw the application's appropriate HTTP exception when access is denied.
Public islands can use the default implementation. Action arguments referring
to other resources need authorization in the action too.

## mount()

```php
public function mount(): void
```

Initialize state from props or load initial editable values. This hook runs on
the first render only, so it does not replace a draft on later updates.

## updated()

```php
use Interact\UpdatedPaths;

public function updated(UpdatedPaths $paths): void
```

Receives an immutable `Interact\UpdatedPaths` object after authorization and before the action.
It contains accepted writable paths and changed reactive prop names.
Ordinary updates report paths whose values changed, independently of whether the
browser sent a dotted edit or replaced an entire array draft. For example, changing
only `data.country` reports `data.country` in both cases. Unchanged siblings are omitted.
Same-length lists report changed positions; changing a list's size, replacing a
container with a scalar, or changing a key that cannot be expressed as a dotted
path reports the containing path. Map key order alone is not a value change.
Use it to clear dependent fields or reset pagination. History restoration supplies
the complete URL-property set, including unchanged values; see [URL state](/guide/url-state). Successful file staging
also invokes it for the affected top-level field.
Parent-input changes use the same hook; `mount()` is not repeated. See
[nested islands](/guide/nesting#reactive-props).

| Member                         | Behavior                                                                     |
| ------------------------------ | ---------------------------------------------------------------------------- |
| `has('data.country')`          | Checks for an exact reported path.                                           |
| `hasAny('search', 'category')` | Checks whether any supplied path was reported. No arguments returns `false`. |
| `toArray()`                    | Returns the reported paths as a `list<string>`.                              |
| `foreach ($paths as $path)`    | Iterates over the paths in their reported order.                             |

Matching is case-sensitive. `has('data')` and `has('data.*')` do not match
`data.country`; parent paths and wildcards are not expanded.

```php
use Interact\UpdatedPaths;

/**
 * Clear a city that may no longer belong to the selected country.
 *
 * @param UpdatedPaths $paths Changed writable paths.
 */
public function updated(UpdatedPaths $paths): void
{
    if ($paths->has('country')) {
        $this->cityId = null;
    }
}
```

This fragment assumes writable `country` and `cityId` properties. See the
[dependent fields example](/demo).

## viewData()

```php
public function viewData(): array
```

Return PHP-only template variables for the current render. The
[Properties page](/guide/state#viewdata) documents value visibility and naming.

## Rendering and disposal

The browser applies returned HTML while preserving newer drafts, focus, local
Alpine state, and matching nested islands. Removing an island releases its
browser resources. See [Requests and DOM updates](/reference/browser).
Browser integrations can observe [request lifecycle events](/reference/request-lifecycle)
without modifying request execution.

A successful action redirect skips view data and rendering. [Renderless actions](/guide/actions#renderless-actions) also skip view data and rendering while returning accepted state and events. Validation errors force a render, and the next successful action renders again to clear that feedback. Downloads accompany an accepted rendered or renderless response. Both helpers are restricted to declared actions; see [redirects and downloads](/guide/redirects-and-downloads#action-lifecycle).

## Deferred initialization

[Lazy loading](/guide/lazy-loading) calls `initialize()` and authorizes the initial placeholder without running `mount()` or rendering island content. Loading constructs a fresh instance, calls `initialize()` again, and authorizes before mounting. Normal updates then follow the lifecycle above.

Pagination can normalize declared state during `viewData()`. Rendered responses
export state afterward so computed results, template variables, and snapshots agree.
See [pagination](/guide/pagination).
