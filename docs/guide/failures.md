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

Alpine's `x-text` displays Interact's error value. Use local Alpine state and
bindings for dismissible details or other presentation; see
[Alpine and Interact](/guide/alpine#events-and-request-feedback).

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

### Resume after a connection or server failure

For `retry` failures, keep the draft editable and offer **Synchronize draft** as
shown above. `sync()` sends the latest writable values without an action. Once
accepted, automatic model updates resume and failure feedback clears.

Resynchronizing does not prove that the failed action was rolled back. For a
save, payment, or job submission, check its application-owned result before
offering another submission. The [demo recovery example](/demo#recovery) shows
explicit synchronization separately from **Accept draft**.

### Reload after expiry or a session change

`snapshot_expired`, `session_changed`, and `csrf_failed` need a fresh page.
Calling `sync()` on the blocked client does not obtain a replacement snapshot.

1. Keep local controls editable and display the failure.
2. Let the user select and copy [`exportDraft()`](/reference/server#exportdraft)
   before leaving. It exports all current writable values, including already
   synchronized values; uploads and locked state are excluded.
3. Follow a normal reload link to obtain fresh authorization, state, and signing.
4. Let the user explicitly restore the copied values into the fresh island and
   review them before saving.

The [demo](/demo#recovery) has a readonly **Draft export** field and a normal
**Reload page** link. No draft is put in the URL or browser storage. Reloading
without copying discards the local draft.

### Return after signing in

For `unauthenticated` with recovery `authenticate`, show a normal link to the
application's sign-in page. Keep the export field available before navigation.
Do not send the failed action or its arguments through the sign-in flow.

This example assumes your application serves sign-in at `/users/login`:

::: code-group

```html [HTML]
<a
  href="/users/login"
  x-show="$server.error && $server.error.recovery === 'authenticate'"
>
  Sign in again
</a>
```

```php [FormHelper]
<?= $this->Html->link('Sign in again', '/users/login', [
    'x-show' => '$server.error && $server.error.recovery === "authenticate"',
]) ?>
```

:::

Use your normal authentication, session renewal, and authorized return destination.
Return to a fresh GET page after sign-in: the old island remains blocked and its
snapshot may belong to the previous session. Offer explicit draft restoration
and review there. Signing in never replays an action.

**Expire demo sign-in** in the recovery example writes a fixture session flag
and returns a real 401. **Sign in again** opens a native, CSRF-protected form;
continuing renews that demonstration session and returns to `/playground`.
This fixture has no credentials. Applications supply their own authentication.

### Restore a copied draft

Draft restoration belongs to the application because it knows the record,
allowed fields, and current permissions. Interact has no generic snapshot-import
or automatic draft-restoration method.

The demo's **Restore a copied draft** form calls a declared `restoreDraft()`
action on the fresh island. Its paste field uses local Alpine `x-model`, while
the action receives the copied JSON as an explicit string argument. The action
accepts only `{"message": "..."}` with a string value. Invalid JSON, other fields,
locked state, and old snapshots produce validation feedback without replacing
the current draft. A successful restore updates `message` and emits
`draft-restored`; it does not call `save()` or increment the acceptance count.

For an editor, restore only allowlisted draft fields into the currently authorized
record. Recheck association IDs and choices using normal application rules.
Never restore an identity, permission, action invocation, or signed snapshot from
the copied document. Reselect uploads after a fresh mount.

The complete [island](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/src/Island/RecoveryIsland.php)
and [template](https://github.com/josbeir/cakephp-interact/blob/main/tests/test_app/templates/island/recovery.php)
are tested together. An application test can assert the distinction between
restoration and acceptance using the [testing helper](/guide/testing):

```php
$this->testIsland('Recovery')
    ->call('restoreDraft', '{"message":"Recovered draft"}')
    ->assertSet('message', 'Recovered draft')
    ->assertSet('saves', 0)
    ->assertDispatched('draft-restored')
    ->call('save')
    ->assertSet('saves', 1);
```

HTTP tests should cover authentication and CSRF. Browser tests should verify
draft retention, a fresh identity after reload or sign-in, explicit restoration,
and no automatic action replay, including when persistence succeeded before a
response was lost.

## Validation errors

A renderable HTTP 422 supplies [`$server.errors`](/guide/forms) and does not pause
updates. It is an accepted render rather than a request failure.

<span id="inspect-an-outcome-in-javascript"></span>

## JavaScript and events

Inspect the [`RequestResult`](/reference/server#request-results) returned by
`call()` or `sync()` when needed. The bubbling `interact:error` event contains
the same failure fields and fires once per failed request, not for each blocked
attempt. Removed islands do not emit new failure UI.

[Request lifecycle diagnostics](/reference/request-lifecycle) provide request
identities, execution stages, timings, and safe outcomes, including queued work
cancelled before sending.

## Server integration

Updates and deferred initialization require the mounting page path in signed
identity. Snapshots missing that path return `invalid_snapshot` (409) before
application hooks or actions run. Preserve local drafts, then reload the page to
obtain a fresh snapshot and correct page links.

The plugin subscribes to CakePHP's `Exception.beforeRender` event and returns
safe versioned JSON for update exceptions after normal logging. Other routes and
application-handled responses retain their handling. Custom error documents get
status-based feedback; exception messages are not parsed to classify failures.
Raw error pages and traces are not inserted into island HTML. See
[custom endpoints](/security#custom-endpoints) for route scope requirements.

Generated Interact errors include `Interact: true`. The
[request marker](/security#request-identification) is informational; supplying it
on another route does not opt that route into Interact exception handling.
