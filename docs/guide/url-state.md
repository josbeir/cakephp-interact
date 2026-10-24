---
title: URL state
description: Bind island properties to query parameters for reloadable links and browser history.
---

# URL state

URL-bound state keeps selected island properties in the page's query string.
Opening the URL initializes those properties; accepted updates change the URL.

## Declaring a binding

Import `State` from `Interact\Attribute`:

```php
#[State(writable: true, url: 'q')]
public string $search = '';

#[State(writable: true, url: true, history: true)]
public int $page = 1;
```

`url: true` uses the property name. A string supplies an alias, giving URLs such
as `/articles?q=cake&page=2`. URL-bound properties require `writable: true` and a
declared default. Props and uploads cannot bind to the query string.

Bind controls normally:

::: code-group

```html [HTML]
<label for="search">Search</label>
<input id="search" name="search" type="search" x-interact:model.live="search" />
```

```php [FormHelper]
<?= $this->Form->control('search', [
    'type' => 'search',
    'label' => 'Search',
    'value' => $search,
    'x-interact:model.live' => 'search',
]) ?>
```

:::

No additional directive, JavaScript registration, or `$server` operation is needed.
See the complete [search example](/demo#search).

## Defaults and types

Values equal to their declared PHP defaults are omitted. With the declarations
above, `/articles` means an empty search and page one. An explicit `q=` remains
an empty string when the property's default is non-empty.

| PHP type | Query representation                                           |
| -------- | -------------------------------------------------------------- |
| `string` | `q=cake`, including an explicit empty value                    |
| `int`    | `page=2`; whole numbers within JavaScript's safe integer range |
| `float`  | `ratio=1.5`; finite numbers                                    |
| `bool`   | `enabled=1` or `enabled=0`                                     |
| `array`  | Flat string lists: `topics[0]=php&topics[1]=testing`           |

For an array declaration:

```php
/**
 * Selected topic identifiers.
 *
 * @var list<string>
 */
#[State(writable: true, url: true)]
public array $topics = [];
```

The decoder also accepts `topics[]=php&topics[]=testing`. An empty bare
`topics=` represents `[]`; `topics[]=` represents `['']`. List order is preserved.
Nullable properties, nested arrays, associative arrays, and non-string list
items are not supported by URL bindings. Ordinary state retains its existing types.

Malformed values fall back independently to the declared default. For example,
`page=abc` uses page one. Correctly typed values still require application rules:
`page=-5` is an integer, but the application should bound it before querying.
Aliases and prefixes allow letters, digits, underscores, and hyphens, starting
with a letter or underscore. Dots, brackets, and prototype-related names are rejected.

## When the URL changes

The URL changes after an accepted PHP render, using the values returned by PHP.
Server corrections are reflected in it. Deferred inputs wait for their next action
or `$server.sync()`; live bindings use their normal debounce.

Renderable validation responses are accepted renders too. Network, protocol,
and authorization failures do not write a new URL. On initial startup, Interact
canonicalizes its owned parameters using `replaceState()`.

The page path, fragment, unrelated parameters, and application history state are
preserved. Declaring a URL binding does not add a route or save application data.

## Browser history

By default, accepted changes replace the current browser entry. This avoids a
Back-button entry for every live search update.

`history: true` adds an entry when that property's accepted value changes. One
response creates at most one entry, even if several history-enabled values change.
Back and Forward restore affected islands in place, without replaying actions
or reloading the page. Unrelated drafts, local Alpine state, and file selections remain.

Restoration uses the normal signed snapshot, authorization, and update hooks. It
supplies every URL-bound property to `updated()`, including unchanged values.
This lets dependent logic respect an explicitly restored page:

```php
use Interact\UpdatedPaths;

/**
 * Reset pagination for a new search unless a page was explicitly supplied.
 *
 * @param UpdatedPaths $paths Accepted writable paths.
 */
public function updated(UpdatedPaths $paths): void
{
    if ($paths->has('search') && !$paths->has('page')) {
        $this->page = 1;
    }

    // Apply the application's page bounds before rendering.
}
```

Apply initial bounds in `mount()` as well. The [search example](/demo#search)
shows both paths using the same normalization method. Keep persistence in actions;
`updated()` also runs during history restoration.

Rapid Back/Forward restores the latest selection. Earlier responses cannot rewrite
its URL, and accepted actions are never repeated. Failed restoration uses normal
[error handling](/guide/failures); the browser-selected URL remains available for
reload or explicit recovery.

## Independent islands

Each query parameter has one island owner. Different classes can use aliases;
repeated instances can supply a prefix:

```php
<?= $this->Interact->render('ResourceSearch', key: 'articles', urlPrefix: 'articles') ?>
<?= $this->Interact->render('ResourceSearch', key: 'products', urlPrefix: 'products') ?>
```

Their `q` bindings become `articles_q` and `products_q`. Supply unique control IDs
when repeated templates contain form controls. The demo at `/search/independent`
shows both concerns together.

For page islands, set `interact.urlPrefix` in the ViewBuilder options. A prefix
is part of signed identity; changing a preserved child's prefix requires a new key.
Removing an island releases its ownership without deleting its URL parameters.
Accidental collisions fail initialization with an alias/prefix diagnostic.

URL values are public, editable input. Keep secrets out of them and authorize
referenced resources on every request. Navigation acceleration and route-path
bindings are separate features.
