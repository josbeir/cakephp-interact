---
title: Browser API
description: Find directive and server bridge contracts, shared request scheduling, DOM behavior, and runtime integration.
---

# Browser API

Use the [Template API](/directives/) for focused examples of each directive and
[`$server`](/reference/server) for the browser bridge's members. This reference
connects those APIs to shared scheduling, ownership, and rendering behavior.

All bindings operate inside an island created by `InteractHelper`.

<span id="native-attributes"></span>

## Alpine directives

| Directive                                 | Behavior                                                    |
| ----------------------------------------- | ----------------------------------------------------------- |
| [`x-interact:model`](/directives/model)   | Keep writable drafts and choose when to send them           |
| [`x-interact:submit`](/directives/submit) | Protect native form submission while its expression settles |
| [`x-interact:poll`](/directives/poll)     | Refresh periodically or evaluate a polling expression       |
| [`x-interact:ignore`](/directives/ignore) | Preserve an element and its subtree during server morphs    |

Use ordinary Alpine `@event`, `:attribute`, `x-show`, and `:class` for events and
presentation. Models use literal writable paths; submit and polling declarations
use Alpine expressions. The feature pages describe accepted modifiers and
initialization errors.

## Widget-owned DOM and custom models

[`x-interact:ignore`](/directives/ignore) skips attribute and descendant morphing
for a matching existing element, while allowing Alpine initialization and
reactivity. Removal and replacement still work.

An `x-modelable` wrapper can connect its local JSON-compatible value to writable
PHP state with [`x-interact:model`](/directives/model#custom-alpine-inputs). See
[JavaScript widgets](/guide/javascript-widgets) for library initialization,
server-driven resets, and lifecycle cleanup.

## Polling

[`x-interact:poll`](/directives/poll) uses one managed poller per island. Its
expression runs in the declaration's current Alpine scope. Hidden documents
suspend timers, and `.visible` additionally restricts viewport visibility.

Ticks skip drafts, scheduled synchronization, outstanding requests, validation
messages, unfinished or failed file selections, and paused failures. No missed
ticks are queued. The [polling guide](/guide/polling) supplies complete PHP and
element implementations.

## Alpine bridge

[`$server`](/reference/server) resolves to the nearest owning island. It exposes
browser-visible state, actions, synchronization, request activity, validation
messages, request failures, draft export, and file transfer controls.

`call()` and `sync()` return `Promise<RequestResult>`: `{ ok: true }` after an
accepted render or `{ ok: false, error }` after a request failure. An accepted
render can contain application validation errors; `ok` does not prove saving
succeeded. See [outcomes in JavaScript](/reference/server#inspect-an-outcome-in-javascript).

## Request activity

Use [`pending()` and `loading()`](/reference/server#show-queued-and-executing-work)
for accepted queued and executing work, optionally matching an action and its
complete argument tuple. `pending()` is immediate; `loading()` defaults to a
200 ms visual delay. Neither includes an unsent debounce timer.

`busy` and island-root `aria-busy` describe an executing request. Form protection
also covers queueing time, with its own form-level `aria-busy`.

## Form submission protection

[`x-interact:submit`](/directives/submit) blocks repeated native submissions and
temporarily disables the form's owned submit buttons until its expression
settles. Model fields stay editable. Associated external submit buttons work
when they belong to the same island.

Application-owned disabled states are preserved across responses. Stable native
form IDs allow an outstanding lock to follow a replacement. Enter and
`requestSubmit()` use the protected path; direct `form.submit()` bypasses submit
events. Custom action buttons remain repeatable unless the application disables
them. Browser protection is not server-side idempotency.

## Structured failures

`$server.error` is null or `{ code, message, status, recovery }`. `status` is the
actual HTTP status or null without a readable response. Recovery is `retry`,
`reload`, `authenticate`, or `none`; it is guidance, never an automatic action.

See [failures and recovery](/guide/failures) for categories, paused requests,
draft export, and the `interact:error` event. Application validation feedback
uses `$server.errors` separately.

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
`script` block. It exposes `window.Alpine`; register custom behavior in an
`alpine:init` listener loaded before the runtime. Adding application JavaScript
does not require rebuilding Interact. See the
[plain JavaScript example](/guide/alpine#access-server-from-a-javascript-file).

For application-owned Alpine, disable `autoScript`, register the npm plugin
before `Alpine.start()`, and omit the standalone bundle. See
[installation](/installation#application-owned-alpine).

Treat generated `data-interact-*` root metadata as runtime-owned. Snapshots and
HTTP envelopes are an implementation protocol, not a public custom-client API.

## File transfers

Native file inputs bind to declared `#[Upload]` fields with `x-interact:model`
and no timing modifiers. Selection starts staging immediately. PHP file objects
and bytes stay outside `$server.state` and snapshots.

Field declarations can lower global staging ceilings with `maxFileSize`
and `maxFiles`. Browser checks improve feedback; PHP enforces
actual bytes, ownership, and capacity independently. See
[field limits](/guide/uploads#set-limits-for-each-upload-field).

[`$server.uploads`](/reference/server#file-transfer-feedback-and-controls) exposes
per-field progress and explicit transfer controls. Opaque file IDs confer no
access outside their verified session, route scope, island, and field. Cancel is
browser-local and does not guarantee PHP stopped processing. Retry transfers
files only, never a blocked application action.

Saves wait for preceding transfers and do not execute after failed or cancelled
prerequisites. Failed selections count as dirty; accepted staged files are
synchronized but remain unsaved application data. Polling skips unfinished or
failed selections. Draft export excludes files and their references.

Native multipart transport uses XMLHttpRequest for upload progress. Ordinary
JSON updates retain fetch. Both use the same endpoint, CSRF protection, response
validation, and reconciliation. Upload bindings and disposal retain nested
island ownership.

The [upload guide](/guide/uploads) includes PHP rendering, complete FormHelper
examples, controls, retention settings, and cleanup.
