---
title: Actions and feedback
description: Call PHP actions from buttons and forms, pass typed arguments, and show loading and request failures.
---

# Actions and feedback

An action changes an island or performs an application operation. Start with
[the counter](/getting-started), then add the examples below to its class and
element.

## Pass an argument

Add an action that accepts one of a small set of increments:

```php [Inside Counter]
/**
 * Accept a supported increment and keep the count within the display range.
 */
#[Action]
public function adjust(int $amount): void
{
    if (!in_array($amount, [-1, 1, 5], true)) {
        throw new InvalidArgumentException('Choose an increment of -1, 1, or 5.');
    }

    $this->count = max(0, min(99, $this->count + $amount));
}
```

Add `use InvalidArgumentException;` to the class file's imports.

Call the action through Alpine's `$server` bridge:

```html [Inside the counter element]
<button type="button" @click="$server.call('adjust', -1)">Subtract one</button>
<button type="button" @click="$server.call('adjust', 5)">Add five</button>
```

The argument must satisfy the PHP type and your application rules. Only methods
marked `Action` are callable. When arguments identify records, authorize those
records inside your application's action flow too.

## Submit a draft

For an island with a writable `title` and an exposed `save()` action:

```php [Inside the island element]
<?= $this->Form->create(null, ['url' => false, 'x-interact:submit' => '$server.call("save")']) ?>
    <?= $this->Form->control('title', [
        'type' => 'text',
        'label' => 'Title',
        'value' => $title,
        'x-interact:model' => 'title',
    ]) ?>
    <?= $this->Form->button('Save') ?>
<?= $this->Form->end() ?>
```

FormHelper generates native markup. Interact handles the form's submit event,
flushes bound controls, and calls `save()`. Pressing Enter and `requestSubmit()`
use the same path. The action decides whether and how to save; see the complete
[article editing example](/guide/forms).

While that submission waits or runs, Interact blocks repeated submissions and
disables its submit buttons. Inputs stay editable. Success, validation feedback,
or a request failure releases the protection while preserving application-owned
disabled states. Other forms and nested islands remain independent.

This works with any helper or handwritten HTML producing `x-interact:submit`
and the `x-interact:model` bindings. It does not depend on CakePHP FormHelper.
Direct `form.submit()` bypasses browser submit events; use `requestSubmit()`.
Custom Alpine submit handlers and `$server.call()` retain their normal queueing
behavior. Browser protection is not server-side idempotency.

<span id="show-progress-and-failures"></span>

## Show feedback for one action

For an island exposing a `remove(int $id)` action, use `pending()` to disable a button as soon as its action is queued. Use
`loading()` for visual feedback after the action has remained pending for 200 ms:

```html [Inside the owning island]
<button
  type="button"
  @click="$server.call('remove', 42)"
  :disabled="$server.pending('remove', 42)"
>
  Remove
</button>
<span x-show="$server.loading('remove', 42)" x-cloak role="status">
  Removing…
</span>
<section :class="{ 'opacity-50': $server.loading('save') }">…</section>
```

The class name is supplied by the application. Only row 42 shows this removal feedback. An action without arguments, such as
`pending('remove')`, matches every invocation of that action. Without a target,
`pending()` and `loading()` match any accepted request. Passing `null` targets
synchronization only: `$server.loading(null)` is useful for a live search.
Both helpers include queued work, not just the executing request; unsent debounce
timers do not count. Ordinary action buttons remain repeatable unless you choose
to disable them.

For a custom visual delay, use the options form:

```html
<span
  x-show="$server.loading({ action: 'remove', args: [42], delay: 500 })"
  x-cloak
  role="status"
>
  Removing…
</span>
```

The delay affects feedback only. It never delays requests or submission protection.
Use `delay: 0` for immediate visibility. See the
[browser reference](/reference/browser#request-activity) for targeting contracts.

`busy` still means a request is executing. `dirty` means local edits need
synchronization; it does not indicate whether database changes were saved.
For field feedback use `$errors` or `$server.errors`; for request failures use
`$server.error` and the [failure guide](/guide/failures).

If you use `x-cloak`, add this rule to your application's stylesheet:

```css
[x-cloak] {
  display: none !important;
}
```

## Synchronize without an action

```html
<button type="button" @click="$server.sync()" :disabled="$server.busy">
  Refresh
</button>
```

A sync sends pending edits and rerenders the island without calling an action.
This is useful for refreshing computed results after another island saves.

Each island processes one request at a time. Pending syncs coalesce; actions
queue in order. Later typing remains local and survives an older response.
Interact does not automatically retry actions: after a connection failure, the
server may already have completed the operation.

For request feedback and recovery, see [failures and recovery](/guide/failures).
