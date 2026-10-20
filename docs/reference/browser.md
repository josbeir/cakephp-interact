---
title: Browser API
description: Alpine model and submission directives, the server bridge, scheduling, and DOM update behavior.
---

# Browser API

The [Alpine and `$server` guide](/guide/alpine), [state guide](/guide/state), and
[action guide](/guide/actions) show practical usage. All bindings operate inside
an island created by `InteractHelper`.

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
| `x-interact:poll`         | Refresh periodically, or evaluate a polling expression       |
| `x-interact:ignore`       | Preserve an element and its subtree during server morphs     |

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
values. File inputs bind to explicitly declared uploads; see [File transfers](#file-transfers).
A directive must be inside an island.
Invalid declarations fail during Alpine initialization; runtime expression and
capture failures use the island's normal failure feedback.

## Widget-owned DOM and custom models

`x-interact:ignore` skips attribute and descendant morphing for an existing element.
Alpine initialization and local reactivity still run. Removal and replacement by
a changed key or element type remain possible. The directive accepts no expression
or modifiers and must be below an island root. Wrap widget-owned markup only;
keep server-rendered errors and other islands outside it. This is independent of
Alpine's `x-ignore`, which prevents Alpine initialization.

An element exposing `x-modelable="value"` can use `x-interact:model="country"`
to connect its local value to writable island state. The modelable expression
must be a property path. Values must be JSON-compatible; library instances and
DOM nodes belong outside reactive state. Do not combine this with `x-model`.

Default bindings defer synchronization. For custom models, `.change` synchronizes
exposed-value changes, `.live` debounces them, and `.blur` waits until focus leaves
the entire wrapper. Accepted server values update the wrapper without another
request. Custom values are captured synchronously before requests and response
reconciliation, retaining newer drafts. A `disabled` wrapper is excluded from
capture and automatic synchronization; disabling the actual widget remains the
application's responsibility.

Use Alpine `init()` and `destroy()` for application-owned library instances. See
[JavaScript widgets](/guide/javascript-widgets) for the visual comparison and a
complete enhanced-select example.

## Polling

Use `x-interact:poll.5s` to refresh the owning island through `$server.sync()`.
An omitted duration defaults to 5000 ms; an omitted or blank expression defaults
to synchronization. An expression such as
`$server.state.running && $server.call('refreshStatus')` uses current Alpine
scope on each tick. There are no separate polling methods on `$server`.

Accept one positive whole-number duration ending in `ms` or `s`, from 1 through
2,147,483,647 milliseconds. Optional `.visible` restricts polling to the
declaration's element intersecting the viewport. Repeated/unknown modifiers,
multiple durations, and multiple declarations owned by the same island are
initialization errors. Nested islands own independent pollers.

The first tick waits one interval. Each subsequent interval starts after the
previous expression settles. Hidden documents suspend timers; visible documents
start a fresh interval. `.visible` applies the same policy to viewport changes.
Polling skips dirty drafts, scheduled model synchronization, pending requests,
validation messages, and paused request failures. No skipped ticks are queued.
Conditions that return false make no request; asynchronous custom functions
should return their promise.

Normal pending/loading feedback and failure recovery apply. Disposal releases
timers and visibility resources without promising cancellation of PHP execution.
See the [polling guide](/guide/polling) for complete examples and application
controls.

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

To refresh after another island saves, listen with
`@article-saved.window="$server.sync()"` inside the receiving island. See the
[event examples](/guide/nesting#refresh-another-island) for parent and sibling
listeners, propagation, and what synchronization refreshes.

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

## File transfers

Native file inputs bind to declared `#[Upload]` fields with `x-interact:model` and
no timing modifiers. A `multiple` input must match a multiple-file declaration.
Selection starts staging immediately; PHP objects and file bytes never become
`$server.state` values.

Optional PHP `#[Upload(maxFileSize: '1MB')]` and
`#[Upload(multiple: true, maxFiles: 3)]` declarations lower the global staging
ceilings. The browser checks size and accepted slots before each queued transfer;
PHP enforces actual bytes and count capacity independently. Extra files in a batch
fail without replacing accepted files or sending their bytes. See
[field limits](/guide/uploads#set-limits-for-each-upload-field) for inheritance,
global ceilings, and application validation.

`$server.uploads.<field>` exposes `pending`, `progress`, and `files`, plus
`cancel(id?)`, `remove(id?)`, and `retry(id?)`. Files expose opaque `id`, `name`,
`size`, `status`, and `progress`; status is `queued`, `uploading`, `ready`, or
`failed`. Progress is 0–100 or `null` when unavailable. Removal accepts browser
item IDs or PHP `TemporaryUpload::id` values. IDs confer no access outside their
verified session, route scope, island, and field.

Cancel is browser-local and does not guarantee PHP stopped processing. Remove
and retry settle like other bridge requests without requiring `.catch()` markup.
Retry transfers files only, never a previously blocked application action.

Unqualified request feedback includes transfers; action selectors continue to
match PHP actions. Saves wait for preceding transfers and do not execute after
failed or cancelled prerequisites. Failed selections count as dirty; accepted
staged files are synchronized but remain unsaved application data. Polling skips
unfinished/failed selections. Draft export excludes files and their references.

The native multipart transport uses XMLHttpRequest for upload progress. Ordinary
JSON updates retain fetch. Both use the same endpoint, CSRF protection, response
validation, and reconciliation. Upload declarations and disposal retain nested
island ownership.

The [upload guide](/guide/uploads) includes PHP rendering, complete FormHelper
examples, controls, retention settings, and cleanup.
