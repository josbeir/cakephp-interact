---
title: PHP API and lifecycle
description: Look up island attributes, mount options, lifecycle hooks, validation feedback, and browser events.
---

<span id="api-and-lifecycle"></span>

# PHP API and lifecycle

Start with [your first island](/getting-started) for a working example. This page
collects the PHP contracts; [browser bindings](/reference/browser) and
[rendering services](/reference/rendering) have their own references.

## Declarations

Import attributes from `Interact\Attribute` and extend `Interact\Island`.

| Declaration                                        | Contract                                                       |
| -------------------------------------------------- | -------------------------------------------------------------- |
| `#[AsIsland('alias', element: 'Interact/name')]`   | Discover a class by alias and render a native element          |
| `#[AsIsland('alias', template: 'Articles/index')]` | Render a native template instead                               |
| `#[Prop]`                                          | Public typed parameter supplied on mount; not browser-writable |
| `#[State]`                                         | Public typed transported value; only PHP can assign it         |
| `#[State(writable: true)]`                         | Explicitly accept browser edits to this state                  |
| `#[Action]`                                        | Expose a public instance method with typed arguments           |

Declare exactly one `template` or `element`. Aliases start with a lowercase
letter and then use lowercase letters, digits, `_`, `.`, or `-`.
The alias is what `render()` receives; it need not match the PHP class name.

Transport supports strings, integers, finite floats, booleans, nullable variants,
and arrays of JSON-compatible values. Objects, entities, resources, and unsafe
prototype-related keys are rejected. Declare concrete supported property types;
an uninitialized required prop must be supplied when mounting.

Every island has one generated owned root, a `div` by default.
`AsIsland(..., tag: 'section')` changes it. Allowed tags are `div`, `section`,
`article`, `aside`, `main`, `nav`, `header`, `footer`, and `fieldset`.
Choose markup that remains valid inside that root, including for nested children.

## Mounting

```php
$this->Interact->render(
    'article-editor',
    props: ['articleId' => 7],
    key: 'article-7',
);
```

`InteractHelper::render(string $alias, array $props = [], ?string $key = null)`
returns trusted rendered island markup. Props must match declared `Prop`
properties. An omitted key defaults to the alias; repeated siblings need explicit
unique stable keys.

Helper options:

| Option       | Default     | Meaning                                                                          |
| ------------ | ----------- | -------------------------------------------------------------------------------- |
| `autoScript` | `true`      | Queue the standalone script in the layout's `script` block                       |
| `scope`      | `'default'` | Server-configured middleware scope bound to snapshots                            |
| `endpoint`   | `null`      | Named `interact:update` route by default; supply a routed URL for a custom scope |

## Lifecycle

### Initial render

1. Resolve the alias and construct a fresh island through CakePHP's container.
2. Assign declared mount props.
3. Call `authorize(IslandContext $context)`.
4. Call `mount()`.
5. Obtain `viewData()` and render declared props, state, errors, and computed data.

### Update

1. Verify snapshot integrity, session binding, scope, and expiry.
2. Construct a fresh island and restore its props and state.
3. Validate browser edits and action argument types; apply accepted edits.
4. Call `authorize()` before application hooks, actions, and rendering.
5. Call `updated(array $paths)` when edits changed state, then the declared action if requested.
6. Obtain `viewData()`, render, and return new state and response effects.

`mount()` does not run again. Constructor dependencies are fresh request
collaborators, not snapshot state. Read the current identity from
`$context->request` rather than trusting transported fields. Authorization may
observe accepted draft changes; authorize any record IDs carried by an action
argument in the action's application flow as well.

### Upload properties

`#[Upload]` declares a public nullable `UploadedFileInterface` initialized to
`null`. `#[Upload(multiple: true)]` declares a public array initialized to `[]`.
Both attributes are imported from `Interact\Attribute`; the interface is
`Psr\Http\Message\UploadedFileInterface`. Uploads cannot also be props or state.

Upload properties are request-local objects and template variables. Snapshots
contain owned opaque references; browser metadata contains IDs, names, and sizes.
The writable JSON state map cannot assign upload references. Successful staging
invokes `updated()` for the affected top-level field after current authorization.

See [file uploads](/guide/uploads) for FormHelper examples, validation, permanent
storage, quotas, and cleanup. Templates receive Interact's `TemporaryUpload`,
which implements the PSR interface and exposes an opaque read-only `id`.

### Hooks and helpers

| Method                                             | Purpose                                                            |
| -------------------------------------------------- | ------------------------------------------------------------------ |
| `authorize(IslandContext $context): void`          | Reject current access before mount/update application behavior     |
| `mount(): void`                                    | Initialize editable state from props or persisted data once        |
| `updated(array $paths): void`                      | React to accepted dotted state paths, for example reset pagination |
| `viewData(): array`                                | Return computed template variables without serializing them        |
| `setErrors(array $errors): void`                   | Normalize CakePHP entity errors or custom message arrays           |
| `errors(): array`                                  | Read current renderable errors                                     |
| `dispatch(string $name, array $detail = []): void` | Queue a native browser event for a successful update               |

`authorize()` is optional and allows access by default. Public islands such as a
counter or simulated job can omit it. Override it when the island displays
protected data or performs actions that require a permission check.

`viewData()` may return entities or result sets for rendering. Its keys cannot
shadow declared props, state, or reserved `errors`. Controller view variables
are not inherited. Errors belong to the current render, rather than durable
application state.

`IslandContext` exposes the current CakePHP `request` and server-configured
`scope` and `endpoint`. `binding()` obtains the current session binding.

A public method without `Action` cannot be invoked by the browser. Lifecycle
hooks are not actions. Invalid declarations, unsupported values, and malformed
updates are rejected; authorization failures should use normal CakePHP exceptions.

## Binding and actions

`x-interact:model`, `x-interact:submit`, and Alpine's `$server` bridge share the
same request engine. An action flushes current controls before invocation. See
[the browser reference](/reference/browser) for all attributes and bridge members.
Synchronization is separate from persistence.

Validation uses `setErrors()` and returns renderable HTTP 422 feedback. It
suppresses success events. See [forms and validation](/guide/forms).

## Validation errors

`setErrors($entity->getErrors())` accepts CakePHP's native errors, while custom
field message lists remain valid:

```php
$this->setErrors([
    'title' => ['unique' => 'This title is reserved.'],
    'comments' => [
        0 => ['body' => ['_empty' => 'A comment is required.']],
    ],
    '_form' => ['Please review these fields.'],
]);

// errors() and $server.errors:
// [
//     'title' => ['This title is reserved.'],
//     'comments.0.body' => ['A comment is required.'],
//     '_form' => ['Please review these fields.'],
// ]
```

- The root maps non-empty string field names to arrays. `_form` is a conventional
  application key, not a special error type.
- Within a field, string values are messages for that field. Their keys, including
  CakePHP rule names, are discarded. Messages retain their order and contents.
- Nested arrays represent child fields or association indices. Their non-empty
  keys extend the dotted path. Already dotted root keys are also accepted;
  overlapping paths append messages in traversal order.
- Empty arrays contribute no errors. Messages must be strings; bare root strings,
  nulls, booleans, numbers, objects, and nesting beyond 32 child levels are rejected
  with `InvalidArgumentException`. Invalid input leaves existing feedback intact.
- Each call replaces the current feedback. `setErrors([])` clears it. Errors are
  response-local, not transported state.

The result remains `array<string, list<string>>` for `errors()`, the reserved
`$errors` template variable, and `$server.errors`. Normalization neither modifies
an entity nor attaches custom errors to it. To have FormHelper render custom
field errors as well, attach them to the entity before passing its `getErrors()`
to `setErrors()`. See [forms and validation](/guide/forms) for a complete example.

## Nesting and events

Children with matching stable keys keep their state through parent updates.
Changing child props or alias requires a new key; duplicate sibling keys are
rejected. Removed children dispose browser work and listeners.

`dispatch()` accepts lowercase event names starting with a letter and continuing
with letters, digits, `:`, or `-`. Events bubble from the island root after a
successful response. Details are browser-visible and untrusted. See
[nested islands and events](/guide/nesting).

## Application integration

`Registry`, `SnapshotCodec`, and `Factory` are container services. Existing
application definitions take precedence. Keep islands freshly constructed and
cache declarations only. See [rendering and services](/reference/rendering)
for the renderer boundary and helper configuration.

Navigation, URL-state features, form adapters, and entity transport are outside
the current implementation.
