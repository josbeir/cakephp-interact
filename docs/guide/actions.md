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

Only declared actions are callable. The updated state and rendered HTML return
in the same response.

<span id="pass-an-argument"></span>

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
while the request settles. The [article form](/examples/article-form) includes
entity validation and saving.

<span id="show-progress-and-failures"></span>
<span id="show-feedback-for-one-action"></span>

## Request feedback

Use [`$server.pending()` and `$server.loading()`](/reference/server#pending)
for request feedback. Use [`setErrors()`](/guide/forms#seterrors) for validation
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
