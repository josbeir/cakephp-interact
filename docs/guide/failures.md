---
title: 'Error handling'
description: Display request failures, inspect recovery categories, and preserve drafts.
---

<span id="failures-and-recovery"></span>

<span id="update-existing-error-bindings"></span>

# Error handling

Interact reports failed requests through `$server.error` and pauses automatic
updates. Local edits remain available.

```html
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

Ordinary actions need no `.catch()` callback. The persistent alert can announce
changed messages. Put padding or a background on a wrapper shown only while an
error exists if the empty alert should take no visual space.

<span id="understand-the-failure"></span>

## Failure fields

`error` is `null` or `{ code, message, status, recovery }`. A null `status` means
no readable response was available. Recovery values are `retry`, `reload`,
`authenticate`, or `none`; none performs an automatic action.

| Code                  | Meaning                                                        | Recovery                                           |
| --------------------- | -------------------------------------------------------------- | -------------------------------------------------- |
| `snapshot_expired`    | The signed snapshot passed its lifetime                        | Reload                                             |
| `session_changed`     | The snapshot belongs to a different session                    | Reload                                             |
| `invalid_snapshot`    | Malformed, tampered, or wrong-scope snapshot                   | Reload                                             |
| `unauthenticated`     | Authentication is required (401)                               | Application sign-in flow                           |
| `csrf_failed`         | CakePHP rejected the security token (403)                      | Reload                                             |
| `forbidden`           | Current access is denied (403)                                 | Application decision                               |
| `upload_too_large`    | Server rejected an oversized upload (413)                      | Choose a smaller file, then submit explicitly      |
| `invalid_request`     | Update input was rejected (400)                                | Correct input, then explicitly try again           |
| `server_error`        | Server returned a 5xx response                                 | Verify the outcome before another explicit attempt |
| `http_error`          | Another HTTP error status                                      | Application decision                               |
| `network_error`       | Fetch failed without a response                                | Verify the outcome before another explicit attempt |
| `unexpected_redirect` | The update redirected instead of returning its protocol        | Reload / check application authentication handling |
| `invalid_response`    | Invalid JSON, content type, envelope, identity, or root markup | Reload / check application logs                    |
| `client_error`        | The browser could not prepare or apply the update              | Reload / check browser diagnostics                 |

<span id="keep-the-draft-and-offer-a-choice"></span>
<span id="complete-demo-implementation"></span>

## Recovery

After a failure, queued actions and pending debounce timers are cancelled.
For a `retry` failure, an explicit action, submit, or sync sends a fresh request
with the current draft. It does not replay failed work. Other categories require
an application decision, fresh mount, or sign-in flow.

```html
<button
  type="button"
  x-show="$server.error && $server.error.recovery === 'retry'"
  @click="$server.sync()"
  :disabled="$server.busy"
>
  Synchronize draft
</button>
```

A failure may occur after a save completed. Verify the outcome before repeating
a persistent operation. Offer a normal application reload/sign-in link where
appropriate. [`exportDraft()`](/reference/server#exportdraft) supports an
intentional copy step; there is no automatic browser storage or restoration.

## Validation errors

A renderable HTTP 422 supplies [`$server.errors`](/guide/forms) and does not pause
updates. It is an accepted render rather than a request failure.

<span id="inspect-an-outcome-in-javascript"></span>

## JavaScript and events

Inspect the [`RequestResult`](/reference/server#request-results) returned by
`call()` or `sync()` when needed. The bubbling `interact:error` event contains
the same failure fields and fires once per failed request, not for each blocked
attempt. Removed islands do not emit new failure UI.

## Server integration

Updates and deferred initialization require the mounting page path in signed
identity. Snapshots issued before that field was introduced return
`invalid_snapshot` (409) before application hooks or actions run. Preserve local
drafts, then reload the page to obtain a fresh snapshot and correct page links.

The plugin subscribes to CakePHP's `Exception.beforeRender` event and returns
safe versioned JSON for update exceptions after normal logging. Other routes and
application-handled responses retain their handling. Custom error documents get
status-based feedback; exception messages are not parsed to classify failures.
Raw error pages and traces are not inserted into island HTML. See
[custom endpoints](/security#custom-endpoints) for route scope requirements.
