---
title: 'Requests and DOM updates'
description: Request queueing, draft preservation, nested ownership, and browser disposal.
---

<span id="browser-api"></span>

# Requests and DOM updates

Interact queues requests per island and applies accepted responses as HTML
updates. These rules are shared by actions, models, forms, polling, and uploads.

<span id="native-attributes"></span>
<span id="alpine-directives"></span>
<span id="alpine-bridge"></span>
<span id="request-activity"></span>
<span id="form-submission-protection"></span>

## Request queue

One request executes at a time per island. Actions queue in order; pending
synchronization coalesces. Different islands operate independently. Actions
capture current controls, including edits not yet sent by blur or debounce.

[`$server.pending()`](/reference/server#pending) includes queued and executing
work. `busy` and island-root `aria-busy` describe executing work.
[`x-interact:submit`](/directives/submit) protects forms from acceptance through
response application, including their queueing time.

<span id="widget-owned-dom-and-custom-models"></span>
<span id="polling"></span>
<span id="scheduling-and-dom-behavior"></span>

## Drafts and responses

Accepted state refreshes surviving controls. Newer local drafts, focus, selection,
and retained Alpine state survive an older response. The server renders current
PHP values; browser drafts can be newer than that markup.

Use [`x-interact:ignore`](/directives/ignore) when a library manages its own markup,
and [`x-interact:model`](/directives/model#custom-alpine-inputs) for modelable values.
[`x-interact:poll`](/directives/poll) skips drafts and feedback instead of
refreshing over them.

## Nested ownership

Model capture, polling, forms, uploads, and `$server` resolve to the nearest
island. Parent updates preserve matching keyed children and their state.
See [Nested islands](/guide/nesting).

<span id="structured-failures"></span>

## Failures and disposal

Request failures use safe structured feedback and pause automatic work. An
accepted validation response remains renderable. See [Error handling](/guide/failures)
for explicit recovery.

Removing an island releases listeners, timers, observers, and in-flight browser
work. Aborting fetch does not cancel PHP execution. Actions are never
automatically retried; a failed connection can occur after persistence succeeded.

## Runtime integration

The Composer bundle starts Alpine CSP and Morph and exposes `window.Alpine`.
Application registration scripts run before it through `alpine:init`; see
[Alpine](/guide/alpine#custom-javascript). An application owning Alpine disables
`autoScript`, installs the npm plugin, and starts its existing instance once.
See [Installation](/installation#application-owned-alpine).

Generated `data-interact-*` metadata and HTTP envelopes are runtime protocols.
Use public directives and the bridge rather than constructing a parallel client.

<span id="file-transfers"></span>

## File transport

Native multipart uploads use XMLHttpRequest for progress; ordinary JSON updates
use fetch. Both share the endpoint, CSRF protection, response validation, and
rendering. File references remain bound to the session, route scope, island, and
field. See [File uploads](/guide/uploads) and
[`$server.uploads`](/reference/server#uploads).
