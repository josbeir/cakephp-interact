---
title: 'x-interact:on'
description: React to named realtime and streamed events within the owning island.
---

# x-interact:on

`x-interact:on:event` evaluates an Alpine expression when the owning island
receives a matching realtime notification or streamed event.

```html
<div
  x-data="{ notification: '' }"
  x-interact:on:notification="notification = $event.detail.text"
>
  <p role="status" x-text="notification"></p>
</div>
```

For complete sender, receiver, and mount examples, see
[notifications between two tabs](/guide/realtime#send-a-notification-to-two-tabs)
or [a streamed report with progress](/guide/streaming#stream-a-report-with-progress).
A listener handles incoming events; realtime topics are declared in `subscriptions()`.

## Expressions and payloads

`$event.type` is the application event name and `$event.detail` is its payload.
Expressions run in the element's Alpine scope. Assign local values, call an
application method with `$event.detail`, or explicitly call `$server.call()`.
Receiving an event does not send a PHP request or change transported state itself.

`$event` is an expression-local `CustomEvent` with no DOM target or propagation
path. Use native Alpine `@event` for DOM events, PHP [`dispatch()` effects](/guide/events),
and request/navigation/error lifecycle events.

## Names and ownership

| Rule                | Behavior                                                                                       |
| ------------------- | ---------------------------------------------------------------------------------------------- |
| Literal event name  | Matches `[a-z][a-z0-9:-]*`; `x-interact:on:report:progress` listens for `report:progress`      |
| Nonempty expression | Uses local Alpine scope; dynamic names and modifiers are not supported                         |
| Nearest island      | Listeners on a root or owned descendant ignore nested and sibling islands                      |
| Multiple listeners  | Each matching listener runs once per incoming event                                            |
| Both delivery paths | Push and streaming events with the same name use the same listener                             |
| Automatic cleanup   | Removing an element or directive releases its listeners; changed bindings rebind during morphs |

Keep listeners outside display-only [`x-interact:stream`](/directives/stream) targets.

## Metadata

`$message` supplies the original envelope when metadata is needed:

| Source                                            | `$message` fields                |
| ------------------------------------------------- | -------------------------------- |
| Realtime `Subscription::listen()`                 | `id`, `topic`, `event`, `detail` |
| Action `streamEvent()` or a custom stream adapter | `name`, `detail`                 |

For example, add a topic check to an existing notification listener:

```html
<div
  x-data="{ notification: '' }"
  x-interact:on:notification="
    $message.topic === 'reports/42' && (notification = $event.detail.text)
  "
>
  <p role="status" x-text="notification"></p>
</div>
```

::: details Raw DOM observers

Advanced observers can listen to the bubbling `interact:push` and `interact:stream`
events on the receiving island root. Their detail contains the original envelope
listed above. `x-interact:on` does not redispatch its expression-local event into
the DOM.

:::
