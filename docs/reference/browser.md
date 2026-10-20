---
title: Browser API
description: Native input and action attributes, the Alpine server bridge, scheduling, and DOM update behavior.
---

# Browser API

The [state guide](/guide/state) and [action guide](/guide/actions) show practical
usage. All bindings operate inside an island created by `InteractHelper`.

## Native attributes

| Attribute                       | Behavior                                                  |
| ------------------------------- | --------------------------------------------------------- |
| `data-interact-model="title"`   | Bind a writable state path; keep edits local by default   |
| `data-interact-update="input"`  | Synchronize after a typing pause, 250 ms by default       |
| `data-interact-debounce="400"`  | Set the input debounce delay in milliseconds              |
| `data-interact-update="blur"`   | Synchronize when focus leaves the control                 |
| `data-interact-update="change"` | Synchronize on the native change event                    |
| `data-interact-submit="save"`   | Capture form submission, flush controls, invoke an action |
| `data-interact-click="next"`    | Capture a click, flush controls, invoke an action         |

Use dotted paths for nested arrays: `form.address.city`. Native text inputs,
textareas, selects, multiple selects, radio groups, numeric values, boolean
checkboxes, and array checkbox groups are supported. Empty nullable numbers can
remain null; false, zero, and empty strings are distinct values. File binding is
not implemented.

`data-interact-click` and `data-interact-submit` name actions without arguments.
Use `$server.call()` when passing arguments. Avoid assigning both action
mechanisms to the same event on a control.

## Alpine bridge

`$server` resolves to the nearest owning island, including in nested markup.

| Member                  | Contract                                                   |
| ----------------------- | ---------------------------------------------------------- |
| `state`                 | Reactive browser-visible state, including current drafts   |
| `busy`                  | A request is in flight                                     |
| `dirty`                 | Local edits are waiting to synchronize                     |
| `errors`                | Current field feedback as a map of message lists           |
| `error`                 | Structured request failure, or null                        |
| `paused`                | Automatic updates are paused after a failure               |
| `exportDraft()`         | Export all current writable values as formatted JSON       |
| `sync()`                | Request synchronization without an action                  |
| `call(action, ...args)` | Queue an exposed PHP action with JSON-compatible arguments |

Use the native model attributes for form controls; reading `state` does not make
PHP-only state writable. The bridge's `sync()` and `call()` return
`Promise<RequestResult>`: `{ ok: true }` after an accepted render, or
`{ ok: false, error }` after a request failure. Ordinary handlers need no catch
callback. A renderable HTTP 422 is an accepted render with field errors available
in `errors`; `ok` does not mean the application saved successfully.

For JavaScript that needs to inspect a request outcome:

```ts
const result = await server.call('save');
if (!result.ok) {
  console.log(result.error.code);
}
```

Here `server` is the owning island's bridge. Errors are also exposed through
`$server.error` and `interact:error`, so application markup can simply bind them.

```html
<button
  type="button"
  @click="$server.call('adjust', 5)"
  :disabled="$server.busy"
>
  Add five
</button>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

`dirty` describes synchronization, not whether the database was saved. HTTP 422
validation is renderable feedback; a request/transport failure appears in
`error`. Render errors accessibly and keep an intentional recovery path for your
application.

## Structured failures

`error` is null or `{ code, message, status, recovery }`. `status` is the actual
HTTP status, or null without a readable response. Recovery is `retry`, `reload`,
`authenticate`, or `none`; it is guidance, never an automatic action.

For all categories, paused requests, draft export, cancellation, and the
`interact:error` event, see [failures and recovery](/guide/failures).

## Scheduling and DOM behavior

- One request runs at a time per island; different islands work independently.
- Pending synchronization coalesces. Actions queue in order.
- Actions read current controls, including edits not yet sent by blur/debounce.
- Responses preserve newer local drafts, focus, selection, and retained local Alpine state.
- Parent morphs preserve matching keyed child islands and their ownership.
- Removing an island cleans up timers, listeners, and its in-flight browser work.

Aborting fetch is not cancellation of PHP execution. Actions are never
automatically retried. Refreshing after expiry starts a new mount; do not replay
a sensitive action automatically.

## Runtime integration

The standalone bundle starts Alpine CSP and Morph. Use it once, with the layout's
`script` block. For application-owned Alpine, disable `autoScript`, register the
npm plugin before `Alpine.start()`, and omit the standalone bundle. See
[installation](/installation#application-owned-alpine).

Treat generated `data-interact-*` root metadata as runtime-owned. Snapshots and
HTTP envelopes are an implementation protocol, not a public custom-client API.
