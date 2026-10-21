---
title: 'Events'
description: Dispatch browser events from PHP and refresh parent, sibling, or matching islands.
---

# Events

`dispatch()` sends a browser event after an accepted island update. Other islands
can listen with Alpine and refresh their own content.

## dispatch()

After an application save succeeds:

```php
$this->dispatch('article-saved', ['id' => $article->id]);
```

The event bubbles from the saving island's root. Its detail is browser-visible.
Validation errors suppress queued success events.

Names start with a lowercase letter and contain lowercase letters, digits,
`:`, or `-`. Details are JSON-compatible values.

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

Use ordinary Alpine `$dispatch` for browser-only events. Interact request failures
emit `interact:error`; see [error handling](/guide/failures).

## events()

`events(): array` returns queued event names and detail arrays for the current
response. These events are response effects rather than transported island state.
