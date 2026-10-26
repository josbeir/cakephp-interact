---
title: 'Events'
description: Dispatch browser events from PHP and refresh parent, sibling, or matching islands.
---

# Events

`dispatch()` sends a browser event after an accepted island update. Other islands
can listen with `#[Action(on: 'event-name')]` to invoke PHP, or use Alpine's
native `@event` syntax for browser behavior and explicit `$server` calls.

## dispatch()

After an application save succeeds:

```php
$this->dispatch(
    'article-saved',
    id: $article->id,
);
```

The event bubbles from the saving island's root. Its detail is browser-visible.
Validation errors suppress queued success events.

Pass detail fields as named arguments:

```php
$this->dispatch(
    'article-saved',
    id: $article->id,
    title: $article->title,
);

// Application-supplied, string-keyed payload:
$this->dispatch('article-saved', ...$detail);
```

Names start with a lowercase letter and contain lowercase letters, digits,
`:`, or `-`. Details must be JSON-compatible values, including nested arrays.
Objects, resources, nonfinite numbers, unsafe keys, and positional payload values
are rejected. The former positional detail-array syntax is no longer supported.
The browser receives the same object, accessible as `$event.detail.id`.

## Action listeners

Import `Action` from `Interact\Attribute` and declare a public island method:

```php
/**
 * Refresh the visible article data.
 */
#[Action(on: 'article-saved')]
public function articleSaved(
    int $id,
    ?string $title = null,
): void {
    // Authorize this ID before accessing
    // protected resources.
}
```

A matching event queues a normal action request for each listening island on the
page, including the emitting island itself. The action renders by default. Its
`viewData()` can reload visible data; the method does not need to query or mutate
anything merely to refresh its island.

Detail keys match parameter names regardless of their order. Extra keys are
ignored, omitted optional parameters use their PHP defaults, and missing required
parameters or invalid types fail normal action validation. A parameterless handler
can refresh an island without consuming the payload. Multiple methods may listen
for the same event; each receives its own queued request.

`on` accepts one literal event name and combines with `render: false` or
`stream: true`. The action remains callable through `$server.call()` using its
normal positional arguments. Listener declarations need no template attributes.

Listeners receive ordinary PHP-dispatched, Alpine, and native browser events
that reach the window. Native propagation applies: `.stop` prevents delivery.
Pending lazy or manual islands begin listening after initialization; events that
arrive earlier are not buffered. Removing an island releases its listeners.

Event actions use existing draft capture, CSRF protection, authorization, request
feedback, and failure handling. They are not automatically retried. Event data is
untrusted; authorize referenced resources in application code. Dispatch events
from successful actions and avoid handlers that re-emit their triggering event.

Streaming and realtime notifications keep their explicit
[`x-interact:on:event`](/directives/on) listeners. They do not automatically invoke
matching `Action(on: ...)` handlers.

## Parent listeners

Inside the receiving parent's element:

```html
<section @article-saved.stop="$server.sync()">
  <!-- Nested article editors. -->
</section>
```

The parent refreshes. Matching keyed children retain their drafts. `.stop`
prevents the event from reaching ancestor or window listeners.

## Sibling listeners

Inside another island's element:

```html
<section @article-saved.window="$server.sync()">
  <!-- Article listing. -->
</section>
```

`.window` receives events that bubble to the window. The listener's `$server`
refers to the receiving island. Remove an earlier `.stop` when the event needs
to reach these listeners.

## Filtering events

For an island with an `articleId` prop:

```html
<section
  @article-saved.window="
    $event.detail.id === $server.state.articleId && $server.sync()
  "
>
  <!-- Content for one article. -->
</section>
```

| Listener                | Receives events from                                |
| ----------------------- | --------------------------------------------------- |
| `@article-saved`        | Its element and descendants                         |
| `@article-saved.stop`   | Its element and descendants, then stops propagation |
| `@article-saved.window` | Any event of that name reaching the window          |

Window listeners may also receive the emitting island's own event. Dispatch from
successful application actions, rather than render hooks, to avoid refresh loops.
Event details are coordination data; authorize and validate subsequent requests
on the server.

## Browser events

Use Alpine's `$dispatch` to emit browser events, for example
`$dispatch('article-saved', { id: 1 })`. Alpine listeners can update local UI or
call `$server` explicitly. Matching declared action listeners automatically queue
a PHP request. See [Alpine and Interact](/guide/alpine#events-and-request-feedback).
Interact request failures emit `interact:error`; see [error handling](/guide/failures).

## events()

`events(): array` returns queued event names and detail arrays for the current
response. These events are response effects rather than transported island state.
