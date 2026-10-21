---
title: 'Properties'
description: Declare props, writable state, supported value types, and PHP-only view data.
---

<span id="state-and-input-binding"></span>

# Properties

Island properties hold values used by PHP and exposed to the browser. Declare
mount parameters with `#[Prop]` and interactive values with `#[State]`.

Import both attributes from `Interact\Attribute`.

<span id="bind-a-text-input"></span>
<span id="choose-when-to-send-edits"></span>

## #[State] {#state}

```php
#[State]
public int $count = 0;
```

PHP can change this value during an action. The template receives `$count`, and
JavaScript can read `$server.state.count`.

### Writable state

```php
#[State(writable: true)]
public string $title = '';
```

`writable: true` allows browser updates. Use
[`x-interact:model`](/directives/model) to bind a control to the property.
Synchronization updates the island; your application decides when to save.

Use `url: true` or a query-name alias to make writable state reloadable through
the query string. See [URL state](/guide/url-state) for types, defaults, and history.

<span id="pass-a-controller-value-explicitly"></span>

## #[Prop] {#prop}

```php
#[Prop]
public int $articleId;
```

Supply required props when mounting:

```php
<?= $this->Interact->render('article-editor', props: ['articleId' => 7]) ?>
```

Props initialize the island and remain available on subsequent requests. They
cannot be changed through model bindings. Props are browser-visible; use current
application authorization to control access to the referenced record.

### Initial editable values

Copy an initial prop to writable state in `mount()`. For an island that declares
`initialTitle` as a prop and `title` as writable state:

```php
/**
 * Initialize the editable title on the first render.
 */
public function mount(): void
{
    $this->title = $this->initialTitle;
}
```

`mount()` runs once. See [Lifecycle](/guide/lifecycle).

## Supported types

| PHP type                | Transported value             |
| ----------------------- | ----------------------------- |
| `string`                | String, including `''`        |
| `int`                   | Integer, including `0`        |
| `float`                 | Finite number                 |
| `bool`                  | `true` or `false`             |
| Nullable supported type | Value or `null`               |
| `array`                 | Nested JSON-compatible values |

Declare concrete property types. Required props may be uninitialized until
mounting. Entities, queries, services, resources, and arbitrary objects cannot
be transported. Files use [`#[Upload]`](/guide/uploads).

<span id="bind-other-native-controls"></span>

## Nested values

```php
/**
 * Editable address fields.
 *
 * @var array{address: array{city: string}}
 */
#[State(writable: true)]
public array $form = ['address' => ['city' => '']];
```

Bind nested values using dotted paths such as `form.address.city`. See
[nested input bindings](/directives/model#nested-values).

<span id="compute-data-for-a-render"></span>

## viewData()

`viewData()` supplies PHP template variables without exposing them as browser
state. Use it for entities, query results, choices, and computed values.

```php
/**
 * Supply a derived value for this render.
 *
 * @return array{characters: int}
 */
public function viewData(): array
{
    return ['characters' => mb_strlen($this->title)];
}
```

The template receives `$characters`; `$server.state.characters` is not created.
The method runs on initial and subsequent renders. Keep database queries in
Table finders and execute them from the island.

Returned names must not duplicate props, state, or the reserved `errors` name.
The [article form example](/examples/article-form) passes an entity to FormHelper
through `viewData()`.
