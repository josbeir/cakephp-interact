---
title: Lazy loading
description: Load an embedded island when visible, after startup, or on demand.
---

# Lazy loading

Lazy loading renders a placeholder first and loads the island when it becomes
visible. Its `mount()`, `viewData()`, and normal template wait until that request.

```php
<?= $this->Interact->render('ResourceCount', loading: 'lazy') ?>
```

The built-in placeholder supplies loading feedback and failure recovery. Once
loaded, the island supports normal actions, models, polling, and uploads.
See the complete [resource count example](/demo#lazy-loading).

## Loading modes

| Mode     | Loads                                                                          |
| -------- | ------------------------------------------------------------------------------ |
| `eager`  | During the page request. This is the default.                                  |
| `lazy`   | When its placeholder enters the viewport, including when a hidden panel opens. |
| `defer`  | After Alpine initializes, even below the fold.                                 |
| `manual` | When `$server.sync()` is called.                                               |

```php
<?= $this->Interact->render('ResourceCount', loading: 'defer') ?>
```

Automatic loading waits while the browser tab is hidden. Without
`IntersectionObserver`, `lazy` falls back to `defer`. There is no preload margin.
Loading options apply when an instance is first created. They do not remount
retained keyed children.

## Manual loading

Use `manual` for optional content that should load only on request:

```php
<?= $this->Interact->render('ResourceCount', loading: 'manual') ?>
```

The default placeholder includes a load button. In a custom placeholder, use:

::: code-group

```html [HTML]
<button type="button" @click="$server.sync()" :disabled="$server.busy">
  Load count
</button>
```

```php [FormHelper]
<?= $this->Form->button('Load count', [
    'type' => 'button',
    '@click' => '$server.sync()',
    ':disabled' => '$server.busy',
]) ?>
```

:::

Repeated triggers share one initialization request. After loading, `sync()`
refreshes normally and does not rerun `mount()`.

## Custom placeholders

Pass the name of a CakePHP element:

```php
<?= $this->Interact->render(
    'ResourceCount',
    props: ['search' => 'CakePHP'],
    loading: 'manual',
    placeholder: 'Loading/resource_count',
) ?>
```

Save it as `templates/element/Loading/resource_count.php`. It receives
only declared props, including their defaults, as PHP variables. Controller
variables, transported state, and `viewData()` are unavailable. The configured
`TemplateView` and its helpers render the element.

The plugin supplies the outer root using the island's declared tag. Render only
the placeholder's contents; placeholders cannot mount nested islands. Give skeletons
enough space to reduce layout shifts.
Custom placeholders own their presentation and should show
[`$server.error`](/guide/failures) and appropriate recovery controls.

## Lifecycle and authorization

The initial request calls `initialize()`, validates props, initializes declared URL input, and calls
`authorize()` before rendering a placeholder. Loading constructs a fresh instance,
calls `initialize()`, assigns the signed props, initializes current URL input, authorizes again, and
then runs `mount()` and rendering. Put expensive initialization in `mount()` or
`viewData()`, rather than constructors or `initialize()`.

Pending tickets use the same session, scope, and expiry protections as snapshots.
They allow initialization only; they cannot execute actions, state edits, or
uploads. Mount hooks must not rely on exactly-once execution: tickets can be
replayed until expiry, and a failed connection can follow completed PHP work.

Before loading, `$server.state`, `errors`, and `uploads` are empty. Request feedback
and `sync()` are available; actions, model bindings, and polling require loaded
content. Initialization counts as synchronization for `pending(null)` and
`loading(null)`.

This feature applies to embedded islands, including nested islands. Whole-page
islands remain server-rendered. Deferred content requires JavaScript; use eager
rendering for content that must appear in the initial response.

## Nested islands and URL state

Parent updates preserve waiting placeholders, in-flight loads, and active keyed
children. Removing a child disposes its browser work. Restoring it creates a fresh
instance. See [nested islands](/guide/nesting).

Pending islands reserve their declared URL parameters without rewriting them or
loading on Back/Forward. Loading uses the current query string. If history changes
while loading, Interact restores the newer selection after initialization without
rerunning `mount()`. Use distinct [URL prefixes](/guide/url-state#independent-islands)
when several reports bind the same query names.

## Failures

Failures leave the placeholder in place, publish `$server.error`, and stop
automatic loading. An explicit `sync()` can retry a retryable failure; expired or
changed-session tickets require a reload. Authentication and authorization failures
retain their existing recovery categories. See [error handling](/guide/failures).

Removing the island disconnects observers and aborts browser transport. A late
response cannot restore removed content. Aborting fetch does not cancel PHP execution.
