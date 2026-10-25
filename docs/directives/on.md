---
title: 'x-interact:on'
description: React to named realtime and streamed events within the owning island.
---

# x-interact:on

`x-interact:on:event` evaluates an Alpine expression when the owning island
receives a matching realtime notification or streamed event.

```html
<div
  x-data="{ percent: 0 }"
  x-interact:on:progress="percent = $event.detail.percent"
>
  <progress max="100" :value="percent" aria-label="Progress"></progress>
</div>
```

Send progress from a [streaming action](/guide/streaming#streamevent):

```php
$this->streamEvent('progress', ['percent' => 50]);
```

For [realtime delivery](/guide/realtime), declare
`Subscription::listen($topic, event: 'progress')` in the island and publish with
`$publisher->publishEvent($topic, 'progress', ['percent' => 50])`. Both paths use
the same template listener. Listeners do not subscribe to topics themselves.

## Expressions and payloads

`$event.type` is the matching application event name and `$event.detail` is its
payload. The expression runs in the element's Alpine scope, so assignments and
application methods work without a separate JavaScript entry point:

```html
<div x-interact:on:notification="notifications.receive($event.detail)"></div>
```

This example assumes an application-defined `notifications` object in Alpine
scope. Calling `$server.call()` is also explicit; receiving an event does not send
a PHP request or change transported state automatically.

`$event` is an expression-local `CustomEvent`, not a redispatched DOM event.
It has no DOM target or propagation path. Use normal Alpine `@event` listeners
for browser events and [`dispatch()` effects](/guide/events), including communication
between parent or sibling islands. Request, navigation, and error lifecycle events
also retain their existing DOM listeners; this directive handles push and streamed
application events only.

## Metadata

`$message` contains the original envelope for optional filtering or bookkeeping:

```html
<div
  x-interact:on:notification="
    $message.topic === 'reports/42' && (notification = $event.detail.text)
  "
></div>
```

This example assumes `notification` exists in Alpine scope.

| Source                                            | `$message` fields                |
| ------------------------------------------------- | -------------------------------- |
| Realtime `Subscription::listen()`                 | `id`, `topic`, `event`, `detail` |
| Action `streamEvent()` or a custom stream adapter | `name`, `detail`                 |

The raw bubbling `interact:push` and `interact:stream` DOM events remain available
to advanced observers with their existing envelope shapes.

## Names and ownership

Event names are literal lowercase identifiers matching `[a-z][a-z0-9:-]*`.
For example, `x-interact:on:report:progress` listens for `report:progress`.
The expression must be nonempty; modifiers and dynamic event names are not supported.

Place a listener on an island root or any owned descendant. Nested and sibling
islands retain independent delivery. Multiple matching listeners in the same
island each run once for an incoming event. An event of the same name received
through push or streaming runs the same listener.

Keep listeners outside display-only `x-interact:stream` targets.

Alpine removes listeners when their elements or directives disappear. Ordinary
morphs preserve local Alpine state and rebind changed declarations. Text and HTML
output can use [`x-interact:stream`](/directives/stream) without an event handler;
server-rendered changes can use `Subscription::refresh()` without JavaScript.
