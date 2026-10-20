---
title: Browser API
description: Alpine model and submission directives, the server bridge, scheduling, and DOM update behavior.
---

# Browser API

The [state guide](/guide/state) and [action guide](/guide/actions) show practical
usage. All bindings operate inside an island created by `InteractHelper`.

<span id="native-attributes"></span>

## Alpine directives

`x-interact:*` connects native controls and forms to their owning island.
`$server` exposes island state and operations inside standard Alpine expressions.
Use Alpine `@event`, `:attribute`, `x-show`, and `:class` for events and presentation.

| Directive                 | Behavior                                                     |
| ------------------------- | ------------------------------------------------------------ |
| `x-interact:model`        | Keep a writable draft local until an action or explicit sync |
| `x-interact:model.live`   | Synchronize after a typing pause, 250 ms by default          |
| `x-interact:model.blur`   | Synchronize when focus leaves the control                    |
| `x-interact:model.change` | Synchronize on the native change event                       |
| `x-interact:submit`       | Protect native submission while its expression settles       |

For example, bind `title` with a 400 ms typing delay:

```html
<input x-interact:model.live.debounce.400ms="title" />
```

Model expressions are literal writable state paths, such as `form.address.city`,
not JavaScript expressions. Submission expressions use the current Alpine scope,
including `$server`, local `x-data`, and `$event`. Both forms and click handlers
invoke actions with `$server.call('action', ...args)`; use `$server.sync()` to
synchronize without an action. Return the request promise from custom submission
functions so protection lasts until their work finishes.

Choose one synchronization modifier: `.live`, `.blur`, or `.change`. Debounce is
only valid with `.live`; `.debounce` uses 250 ms and `.debounce.400ms` overrides it.
Durations must be whole milliseconds from 0 to 60,000. Unknown, repeated, or
conflicting modifiers are errors. Submission directives accept no modifiers and
require a non-empty expression on a form. Do not also attach `@submit` handlers to
the same form. Use one model binding per control; do not combine `x-model` with
`x-interact:model` or declare multiple Interact model directives on a control.

Native text inputs, textareas, selects, multiple selects, radio groups, numeric
values, boolean checkboxes, and array checkbox groups are supported. Empty
nullable numbers can remain null; false, zero, and empty strings are distinct
values. File binding is not implemented. A directive must be inside an island.
Invalid declarations fail during Alpine initialization; runtime expression and
capture failures use the island's normal failure feedback.

## Alpine bridge

`$server` resolves to the nearest owning island, including in nested markup.

| Member                      | Contract                                                   |
| --------------------------- | ---------------------------------------------------------- |
| `state`                     | Reactive browser-visible state, including current drafts   |
| `pending(target?, ...args)` | Matching queued or executing work, immediately             |
| `loading(target?, ...args)` | Matching work after a 200 ms visual delay                  |
| `busy`                      | A request is in flight                                     |
| `dirty`                     | Local edits are waiting to synchronize                     |
| `errors`                    | Current field feedback as a map of message lists           |
| `error`                     | Structured request failure, or null                        |
| `paused`                    | Automatic updates are paused after a failure               |
| `exportDraft()`             | Export all current writable values as formatted JSON       |
| `sync()`                    | Request synchronization without an action                  |
| `call(action, ...args)`     | Queue an exposed PHP action with JSON-compatible arguments |

Use `x-interact:model` for form controls; reading `state` does not make
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

## Request activity

`pending()` and `loading()` use the same targets:

| Query                                       | Matches                                          |
| ------------------------------------------- | ------------------------------------------------ |
| `pending()`                                 | All accepted queued/executing work               |
| `pending('save')`                           | Any invocation of `save`                         |
| `pending('remove', 42)`                     | `remove` with the complete argument tuple `[42]` |
| `pending(null)`                             | Synchronization only                             |
| `pending({ action: 'remove', args: [42] })` | The equivalent object selector                   |

`loading()` accepts all these forms and adds `delay` in its object form:
`loading({ action: 'save', delay: 500 })`. Delay defaults to 200 ms and must be an
integer from 0 to 60,000 ms. Omitting `action` matches all requests; setting it to
`null` matches synchronization. Argument filters require a named action and a
complete JSON argument tuple. Matching preserves scalar types and ignores object
key order; `42` and `'42'` are different arguments.

`pending()` is immediate. `loading()` waits through a continuously matching pending
interval, including time queued behind another request. Overlapping matching work
keeps the indicator visible. Equivalent delayed queries share their timer; timers
are released when the interval ends or the island disappears. Neither helper
includes an unsent debounce timer. Both helpers are reactive in Alpine bindings.

Use Alpine `x-show`, `:disabled`, and `:class` for presentation. There are no
loading-specific HTML attributes. The existing `busy` property and island-root
`aria-busy` retain their executing-request meaning.

## Form submission protection

Forms using `x-interact:submit` automatically block repeat submissions from
acceptance until response application completes. Their owned native submit
buttons are temporarily disabled and the form receives `aria-busy="true"` while
queued or running. Model inputs stay editable. Associated submit buttons outside
the form element are supported when they belong to the same island.

Application-owned disabled states are preserved, including those supplied by a
server render. Use stable native form IDs for forms that may be dynamically
replaced; surviving/keyed forms keep their outstanding lock.
Independently replaced, unkeyed forms have a new identity.

The protection works with native submit events, including Enter and
`requestSubmit()`, regardless of which PHP helper generated the HTML. Direct
`form.submit()` bypasses submit events. Custom Alpine handlers and direct
`$server.call()` invocations remain repeatable. No automatic retry or server-side
exactly-once guarantee is introduced.

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
