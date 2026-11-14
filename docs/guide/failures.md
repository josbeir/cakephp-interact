---
title: 'Error handling'
description: Display request failures, choose a recovery action, and customize expiry feedback.
---

<span id="failures-and-recovery"></span>

# Error handling

Interact exposes failed requests through `$server.error`. A failure preserves
local edits, pauses automatic updates, and cancels queued actions and pending
debounce timers. Actions are never automatically replayed.

<span id="validation-errors"></span>

Validation errors are available through `$server.errors`. A renderable HTTP 422
response does not pause updates; see [form validation](/guide/forms).

<span id="update-existing-error-bindings"></span>

## Display an error

```html
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

The error is `null` until a request fails. Ordinary actions need no `.catch()`
callback. Use Alpine for any additional presentation, such as dismissing an alert.

<span id="keep-the-draft-and-offer-a-choice"></span>
<span id="recovery"></span>

## Recover from a failure

Use `$server.error.recovery` to choose what the user can do next:

| Recovery       | Application response                                                                 |
| -------------- | ------------------------------------------------------------------------------------ |
| `retry`        | Correct the input or connection, then offer explicit synchronization or submission.  |
| `reload`       | Let the user preserve important input, then reload for a fresh island.               |
| `authenticate` | Offer the application's sign-in flow, followed by a fresh page.                      |
| `none`         | Handle the failure according to application rules, such as explaining denied access. |

A failed response does not prove that saving failed. Verify the outcome before
repeating a persistent operation. Copy important drafts before reloading or
signing in; Interact does not automatically store or restore them.

<span id="resume-after-a-connection-or-server-failure"></span>

### Synchronize the draft

For `retry` failures, `sync()` sends the latest writable values without invoking
an action. An accepted response clears the failure and resumes automatic updates.
Synchronization does not undo or repeat the failed action.

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

<span id="reload-after-expiry-or-a-session-change"></span>

### Reload or sign in

For `reload`, return to a fresh GET page after preserving important input.
Calling `sync()` on a blocked client cannot replace an expired snapshot.
[Expiry failures offer a reload prompt by default](#customize-the-expiry-prompt).

<span id="return-after-signing-in"></span>

For `authenticate`, use the application's normal sign-in flow and return to a
fresh GET page. The old island remains blocked. This example assumes sign-in is
served at `/users/login`:

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

<span id="restore-a-copied-draft"></span>

### Preserve a draft

[`exportDraft()`](/reference/server#exportdraft) returns JSON containing current
writable values, including already synchronized values. Offer it for the user
to select and copy before leaving. Uploads and locked state are excluded.

Restoration belongs to the application: validate allowed fields against the
currently authorized record, let the user review them before saving, and reselect
uploads. Never import an identity, permissions, action, or signed snapshot.

<span id="complete-demo-implementation"></span>

The [tested recovery example](/demo#recovery) demonstrates copying, reloading or
signing in, and explicitly restoring a draft for review.

<span id="default-expiry-prompt"></span>

## Customize the expiry prompt

`snapshot_expired`, `session_changed`, and `csrf_failed` show a native browser
confirmation asking whether to reload and warning that unsaved changes will be
lost. **OK** reloads; **Cancel** keeps the page open with editable drafts and
paused updates. Other failures do not open this prompt.

All islands share one prompt per page visit. A successful enhanced page visit
resets it; cancelled visits, fragment changes, and island URL-state changes do not.

### Suppress the default prompt

```js
document.addEventListener('interact:error', (event) => {
  event.preventDefault();
});
```

The event bubbles from each island to `document`. Call `preventDefault()`
synchronously to suppress the prompt across the page. It affects presentation
only: error feedback, paused updates, cancelled queued work, and request results
remain unchanged.

<span id="replace-or-suppress-the-prompt"></span>

::: details Custom dialog example

This Alpine component replaces the native prompt with an application-owned
`<dialog>`. Put the markup in the layout outside islands. Alpine manages the
window listener and removes it when the component leaves the page.

```html
<div x-data="expiryRecovery" @interact:error.window="handle($event)">
  <dialog
    x-ref="dialog"
    aria-labelledby="expiry-recovery-title"
    @close="closed()"
  >
    <h2 id="expiry-recovery-title">This page has expired</h2>
    <p>
      Reloading discards unsaved changes. Keep this page open to copy your work.
    </p>
    <form method="dialog">
      <button type="submit" value="cancel">Keep this page open</button>
      <button type="submit" value="reload">Reload page</button>
    </form>
  </dialog>
</div>
```

Register the factory once in an external script loaded before the Interact runtime,
as described in [custom JavaScript](/guide/alpine#custom-javascript):

```js
document.addEventListener('alpine:init', () => {
  window.Alpine.data('expiryRecovery', () => ({
    handle(event) {
      if (
        !['snapshot_expired', 'session_changed', 'csrf_failed'].includes(
          event.detail.code,
        )
      )
        return;

      event.preventDefault();
      const dialog = this.$refs.dialog;
      if (!dialog.open) {
        dialog.returnValue = 'cancel';
        dialog.showModal();
      }
    },
    closed() {
      if (this.$refs.dialog.returnValue === 'reload') {
        window.location.reload();
      }
    },
  }));
});
```

For the npm integration, register the same factory before `Alpine.start()`.
Keep one recovery component in the layout; the `open` check combines failures
arriving while its dialog is already visible.

Applications own custom-dialog deduplication. Suppressed events do not consume
the default prompt allowance. Cancel the event before awaiting asynchronous work.

:::

<span id="understand-the-failure"></span>
<span id="failure-fields"></span>

## Failure reference

`$server.error` is `null` or an object with these fields:

| Field      | Meaning                                                                              |
| ---------- | ------------------------------------------------------------------------------------ |
| `code`     | Stable identifier for the failure.                                                   |
| `message`  | User-facing failure message.                                                         |
| `status`   | HTTP status, or `null` when no readable response was available.                      |
| `recovery` | `retry`, `reload`, `authenticate`, or `none`; no recovery action runs automatically. |

::: details Failure codes

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

:::

<span id="inspect-an-outcome-in-javascript"></span>
<span id="javascript-and-events"></span>

### JavaScript events and results

The bubbling `interact:error` event contains the same failure fields and fires
once per failed request, not for each blocked attempt. Removed islands do not
emit new failure UI. Inspect the [request result](/reference/server#request-results)
returned by `call()` or `sync()` when the caller needs to handle an outcome.

[Request lifecycle events](/reference/request-lifecycle) provide timings and safe
outcomes. Those diagnostic events are non-cancelable and cannot intercept requests.

<span id="server-integration"></span>

::: details Server integration

The plugin uses CakePHP's `Exception.beforeRender` event to return safe versioned
JSON for update exceptions after normal logging. Application-handled responses
retain their handling; custom error documents receive status-based feedback.
Exception messages are not parsed to classify failures, and raw error pages and
traces are not inserted into island HTML.

Updates and deferred initialization require the mounting page path in signed
identity. A snapshot missing that path returns `invalid_snapshot` (409) before
application hooks or actions run and requires a fresh mount.

Generated errors include `Interact: true`. The request marker is informational;
adding it to another route does not enable Interact exception handling. See
[custom endpoints](/security#custom-endpoints) and
[request identification](/security#request-identification).

:::
