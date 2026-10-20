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
<button
  type="button"
  @click="$server.call('adjust', -1)"
  :disabled="$server.busy"
>
  Subtract one
</button>
<button
  type="button"
  @click="$server.call('adjust', 5)"
  :disabled="$server.busy"
>
  Add five
</button>
```

The argument must satisfy the PHP type and your application rules. Only methods
marked `Action` are callable. When arguments identify records, authorize those
records inside your application's action flow too.

## Submit a draft

For an island with a writable `title` and an exposed `save()` action:

```html
<form data-interact-submit="save">
  <label>Title <input data-interact-model="title" /></label>
  <button type="submit" :disabled="$server.busy">Save</button>
</form>
```

This captures the form's submit event, sends current bound controls, and calls
`save()`. Pressing Enter uses the same path. The action decides whether and how
to save; see the complete [article editing example](/guide/forms).

## Show progress and failures

Put feedback inside the owning island:

```html
<p role="status" x-show="$server.busy" x-cloak>Updating…</p>
<p x-show="$server.dirty" x-cloak>There are local edits.</p>
<p role="alert" x-text="$server.error"></p>
```

`busy` means a request is running. `dirty` means there are local edits waiting
to synchronize; it does **not** mean an entity differs from its saved database
version. `$server.error` is a request failure; field validation feedback lives in
`$server.errors` and the template's `$errors` variable.

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
