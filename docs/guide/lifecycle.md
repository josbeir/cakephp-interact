---
title: 'Lifecycle'
description: Look up island hooks and initial-render and update order.
---

# Lifecycle

Interact constructs a fresh island for every request. Declared props and state
are restored between requests; services and protected properties remain local
to the current instance.

## Initial render

1. Resolve the alias and construct the island through CakePHP's container.
2. Assign mount props.
3. Call `authorize(IslandContext $context)`.
4. Call `mount()`.
5. Call `viewData()` and render the declared element or template.

## Update

1. Verify the signed snapshot, session, scope, and expiry.
2. Construct a fresh island and restore its declared properties.
3. Validate and apply browser edits and validate action argument types.
4. Call `authorize()`.
5. Call `updated()` for changed fields, then the requested action.
6. Call `viewData()`, render, and return state, errors, and events.

The original controller action, layout, and `mount()` do not run again.
Authorization can observe accepted edits and runs before application update hooks,
actions, and rendering.

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
public function updated(array $paths): void
```

Receives changed writable paths after authorization and before the action.
Use it to clear dependent fields or reset pagination. Successful file staging
also invokes it for the affected top-level field.

```php
/**
 * Clear a city that may no longer belong to the selected country.
 *
 * @param list<string> $paths Changed writable paths.
 */
public function updated(array $paths): void
{
    if (in_array('country', $paths, true)) {
        $this->cityId = null;
    }
}
```

This fragment assumes writable `country` and `cityId` properties. See the
[dependent fields example](/examples/dependent-fields).

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
