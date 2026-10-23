---
title: Request lifecycle
description: Observe queued island requests, response application, timings, and safe outcomes.
---

# Request lifecycle

Request lifecycle events observe accepted island queue work and expose safe browser
diagnostics. They work with the Composer bundle and npm integration without configuration.

```js
document.addEventListener('interact:request-finished', (event) => {
  const { requestId, islandId, kind, outcome, timings } = event.detail;
  console.debug('Interact request', {
    requestId,
    islandId,
    kind,
    outcome,
    timings,
  });
});
```

This example opts into console output. Interact itself keeps no diagnostic history
and performs no automatic logging or telemetry.

## Events

Events bubble from the island root to `document` and `window`. If the root has
been removed, remaining observations are dispatched on its owner document with
the same identifiers. Use `islandId` to filter an individual island, including
cancellation after removal.

| Event                       | Timing                                                                                                |
| --------------------------- | ----------------------------------------------------------------------------------------------------- |
| `interact:request-queued`   | A distinct task enters the queue.                                                                     |
| `interact:request-started`  | Execution starts, including preparation that may prevent a send.                                      |
| `interact:request-response` | The transport returns a response, before Interact validates it.                                       |
| `interact:request-applied`  | Accepted state, controls, DOM, selection, and response effects have been applied.                     |
| `interact:request-finished` | Cleanup and failure feedback finish, before caller completion handlers and the next queued execution. |

Notifications are synchronous and non-cancelable. `preventDefault()` does not
cancel a request. Returned promises are not awaited; handle asynchronous work in
your listener. Listener exceptions follow native browser error reporting and do
not become island request failures. A listener can use existing application APIs
to queue new work; that work waits until the current queue notification completes.

Within one request, the normal order is queued, started, response, applied,
finished. Failed or cancelled work can omit intermediate events. An unsent task
discarded from the queue emits queued and finished only. Each accepted task
finishes once; events from different requests can interleave.

## Event details

Every event exposes a frozen `detail` snapshot:

| Field        | Meaning                                                                                          |
| ------------ | ------------------------------------------------------------------------------------------------ |
| `requestId`  | Increasing numeric identity, unique within the current document, including enhanced page visits. |
| `islandId`   | The island's runtime identity.                                                                   |
| `kind`       | `action`, `sync`, `initialize`, `upload-stage`, `upload-remove`, or `restore`.                   |
| `action`     | Action name, or `null` for passive work.                                                         |
| `phase`      | `queued`, `started`, `response`, `applied`, or `finished`.                                       |
| `elapsedMs`  | Time since queue acceptance at this observation.                                                 |
| `transport`  | `fetch` or `xhr` after invocation; otherwise `null`.                                             |
| `httpStatus` | Transport response status when available; otherwise `null`.                                      |

Applied details also include `rendered` and an `outcome` of `success` or
`validation`. Renderless responses emit applied with `rendered: false`.
Redirects do not emit applied.

Finished details add:

| Field     | Meaning                                                                   |
| --------- | ------------------------------------------------------------------------- |
| `outcome` | `success`, `validation`, `redirect`, `failed`, `cancelled`, or `ignored`. |
| `stage`   | Last execution stage: `queue`, `prepare`, `response`, or `apply`.         |
| `failure` | Frozen safe `RequestFailure` for failed work; otherwise `null`.           |
| `timings` | Frozen queue, preparation, response, application, and total durations.    |

Diagnostics describe task handling; [`call()` and `sync()`](/reference/server#request-results)
retain their existing result contract. `ignored` identifies a response suppressed
by a newer page visit or a cancelled enhanced redirect. Applied work remains
applied if an observer subsequently removes the island.

Failure information follows [`$server.error`](/guide/failures). Details exclude
arguments, state, snapshots, URLs, headers, file metadata, raw response bodies,
and original exceptions or stack traces. Action names and island identifiers
remain application metadata; choose what your monitoring integration records.

## Timings

Durations use the browser's monotonic performance clock and are measured in
milliseconds. Phases that were never entered are `null`.

| Field        | Interval                                                                        |
| ------------ | ------------------------------------------------------------------------------- |
| `queueMs`    | Queue acceptance to execution, or cancellation for unsent work.                 |
| `prepareMs`  | Execution to transport invocation, or completion if nothing was sent.           |
| `responseMs` | Transport invocation through body reading and protocol validation, or failure.  |
| `applyMs`    | Accepted response handling and effects, including an awaited enhanced redirect. |
| `totalMs`    | Queue acceptance through final cleanup.                                         |

Total time includes bookkeeping and notification handlers and need not equal the
sum of individual phases. These are browser observations, not PHP-only or
network-only measurements. With XMLHttpRequest, the response body is already
buffered when the response notification fires.

## Queueing, streaming, and cancellation

Pending synchronization coalesces into one task with one request identity and
one lifecycle. Repeated initialization calls share their pending task too.
Attempts rejected before entering the queue produce no lifecycle record; existing
failure feedback continues to apply.

Polling, form bindings, and models use the existing action/sync bridge, so their
requests have the same lifecycle. Upload staging uses `xhr`; removal uses `fetch`.
URL restoration is a separate `restore` task and never replays an action.

A finite SSE response has one request lifecycle. Its HTTP status can be 200 while
the accepted terminal result produces `outcome: 'validation'`. Provisional stream
messages continue through [`interact:stream`](/guide/streaming#streamevent).

Disposal, redirects, and a previous failure can discard queued work. Such work
finishes as cancelled without duplicating `interact:error`. Active browser
cancellation does not establish whether PHP executed or saved an action. No hook
automatically retries work. Use the existing
[navigation events](/directives/navigate#document-events) for document visits.

## Alpine and listener cleanup

Alpine can observe the same events:

```html
<div
  x-data="{ lastMs: null }"
  @interact:request-finished.window="lastMs = $event.detail.timings.totalMs"
>
  <output x-text="lastMs"></output>
</div>
```

This displays the last observed request duration across islands. Keep loading
feedback on [`$server.pending()` and `$server.loading()`](/reference/server#pending).

For application-owned native listeners, use an `AbortController` signal and abort
it during the owning component's teardown. Alpine releases its own directive listeners.

The npm package exports `RequestLifecycleDetail`, `RequestKind`, `RequestOutcome`,
and `RequestTimings`. Native element, document, and window listeners infer the
appropriate detail type from the event name.
