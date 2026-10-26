---
title: 'Actions'
description: Declare callable PHP methods, pass arguments, and validate application operations.
---

<span id="actions-and-feedback"></span>

# Actions

Actions are PHP methods that can be called from the browser. Mark a public
instance method with `#[Action]` and invoke it through `$server.call()`.

## #[Action] {#action}

Import `Action` from `Interact\Attribute`. Inside an island with a `count` property:

```php
/**
 * Increase the count by one.
 */
#[Action]
public function increment(): void
{
    $this->count++;
}
```

```html
<button type="button" @click="$server.call('increment')">Add one</button>
```

Only declared actions are callable. By default, the updated state and rendered
HTML return in the same response.

`@click` is Alpine's event listener; `$server.call()` is Interact's PHP bridge.
For a local interaction, use the same Alpine syntax with local state instead,
such as `@click="expanded = !expanded"`. That expression sends no request.
See [Alpine and Interact](/guide/alpine) for a complete example.

## Renderless actions {#renderless-actions}

`#[Action(render: false)]` returns accepted state and events without calling
`viewData()`, rendering the template, or morphing the island. Use it for operations
such as saving a draft when PHP-rendered content does not need to change:

```php
/**
 * Persist the application draft without rebuilding the current view.
 */
#[Action(render: false)]
public function saveDraft(): void
{
    // Marshal, validate, and explicitly save in application code.
    $this->savedCount++;
    $this->dispatch('draft-saved');
}
```

The fragment assumes a declared `savedCount` state property. The
[complete draft editor](/demo#renderless-actions) includes CakePHP persistence
and a FormHelper form.

```html
<button type="button" @click="$server.call('saveDraft')">Save draft</button>
<p x-text="$server.state.savedCount"></p>
```

Alpine expressions and bound controls reflect accepted state. PHP-rendered text,
computed view data, and nested island markup retain their previous content until
a rendering response, such as `$server.sync()`. Existing children keep their
identities and remain interactive.

### Conditional rendering

Call `$this->skipRender()` inside an ordinary action to skip rendering for that
invocation:

```php
/**
 * Count a small signal quietly; render larger changes.
 */
#[Action]
public function signal(int $count = 1): void
{
    $this->signals += $count;
    if ($count === 1) {
        $this->skipRender();
    }
}
```

This fragment assumes a declared `signals` state property. `skipRender()` records
a response preference and does not stop PHP execution. Repeated calls are safe;
the preference resets for the next request. Calling it from hooks or outside a
declared action throws `LogicException`.

### Validation and request behavior

Validation errors set through `setErrors()` always render. After a validation
render, the next successful action renders once more to clear FormHelper error
markup. Subsequent successful renderless actions skip rendering again.

| Request                                                                           | Rendering                                |
| --------------------------------------------------------------------------------- | ---------------------------------------- |
| Successful renderless action                                                      | Skip view data, template, and morph      |
| Current errors or previously rendered errors                                      | Render feedback or clear it              |
| Initial mount, lazy initialization, synchronization, uploads, history restoration | Render normally                          |
| Successful redirect                                                               | Navigate without rendering               |
| Successful download                                                               | Follow the action's rendering preference |

Renderless actions use the same authorization, writable-state checks, signed
snapshots, queue, URL bindings, uploads, pending/loading feedback, and form
submission protection as other actions. Newer edits, focus, and selection survive
the response. Calls still return the existing `Promise<RequestResult>` contract;
there is no extra browser method or modifier.

The request still waits for PHP to finish. Renderless actions do not run in the
background, bypass validation, automatically save, or automatically retry a
failed operation. Keep operations idempotent where your application needs safe
explicit retries.

<span id="pass-an-argument"></span>

## Listening for events

Use `#[Action(on: 'article-saved')]` to invoke an action when a matching browser
event reaches the window. Event detail binds to typed PHP parameters by name.
See [action listeners](/guide/events#action-listeners) for delivery, defaults,
and authorization behavior.

## Passing arguments

Declare typed parameters and validate allowed values in PHP:

```php
/**
 * Apply one of the supported adjustments.
 */
#[Action]
public function adjust(int $amount): void
{
    if (!in_array($amount, [-1, 1, 5], true)) {
        throw new InvalidArgumentException('Choose -1, 1, or 5.');
    }

    $this->count = max(0, $this->count + $amount);
}
```

Import `InvalidArgumentException` in this class file.

```html
<button type="button" @click="$server.call('adjust', 5)">Add five</button>
```

Arguments must be JSON-compatible values that satisfy the declared PHP types.
When an argument identifies a record, authorize access to that record in the
action's application flow.

<span id="submit-a-draft"></span>

## Form submissions

Use [`x-interact:submit`](/directives/submit) to call an action from a form.
It captures current bound controls and protects against repeated submissions
while the request settles. The [article form](/demo#forms) includes
entity validation and saving.

<span id="show-progress-and-failures"></span>
<span id="show-feedback-for-one-action"></span>

## Request feedback

Use [`$server.pending()` and `$server.loading()`](/reference/server#pending)
with Alpine's `:disabled` and `x-show` bindings for request feedback. Interact
reports request activity; Alpine controls its presentation. Use
[`setErrors()`](/guide/forms#seterrors) for validation
messages and [`dispatch()`](/guide/events#dispatch) to announce application events.

<span id="synchronize-without-an-action"></span>

## Synchronization without an action

`$server.sync()` sends current edits and renders the island without invoking an
action. Its [API entry](/reference/server#sync) describes refresh behavior.

## Authorization and retries

`authorize()` runs on every update before application hooks, actions, and
rendering. Read the current identity from `IslandContext`; transported IDs do
not establish current access.

Actions queue in order within an island and are never automatically retried.
A failed connection can occur after an operation completed. See
[error handling](/guide/failures) before implementing recovery for persistent
operations.

Actions can also [redirect](/guide/redirects-and-downloads) or request a [download](/guide/redirects-and-downloads#downloads) through PHP helpers. The template still uses `$server.call()`.

## Streaming actions

`#[Action(stream: true)]` enables `stream()`, `streamElement()`, and
`streamEvent()` during execution. Call it through the same `$server.call()` API.
Combine it with `render: false` to retain incremental output without a final morph.
See [Streaming](/guide/streaming) for targets, completion, and deployment requirements.
