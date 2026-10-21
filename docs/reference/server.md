---
title: '$server'
description: Look up island state, actions, synchronization, activity, errors, and transfer controls.
---

<span id="member-reference"></span>

# $server

`$server` gives Alpine expressions and JavaScript access to an island's state
and actions. It resolves to the nearest containing island.

| Member                                  | Description                                           |
| --------------------------------------- | ----------------------------------------------------- |
| [`state`](#state)                       | Props and state, including local edits                |
| [`call(action, ...args)`](#call)        | Call a declared PHP action                            |
| [`sync()`](#sync)                       | Send edits and render without an action               |
| [`pending(target?, ...args)`](#pending) | Match queued or executing requests                    |
| [`loading(target?, ...args)`](#loading) | Match requests after a visual delay                   |
| [`busy`](#busy)                         | Whether a request is executing                        |
| [`dirty`](#dirty)                       | Whether edits or file selections need synchronization |
| [`errors`](#errors)                     | Field validation messages                             |
| [`error`](#error)                       | Current request failure or `null`                     |
| [`paused`](#paused)                     | Whether automatic updates are paused after a failure  |
| [`exportDraft()`](#exportdraft)         | Export writable values as JSON                        |
| [`uploads`](#uploads)                   | Per-field file progress and controls                  |

<span id="state-and-scope"></span>

## state

```html
<p x-text="'Count: ' + $server.state.count"></p>
```

For `#[State] public int $count`, PHP sees `$count` and JavaScript sees
`$server.state.count`. Props are available the same way. Only writable state can
receive browser edits. Entities and `viewData()` are not included.

Use [`x-interact:model`](/directives/model) for inputs. Local edits appear in
`state` immediately; PHP-rendered text changes on the next accepted response.

<span id="read-data-and-call-an-action"></span>
<span id="call-php-actions-with-arguments"></span>

## call()

```html
<button type="button" @click="$server.call('adjust', 5)">Add five</button>
```

`call(action, ...args): Promise<RequestResult>` queues a declared `#[Action]`
method with JSON-compatible arguments. PHP validates types and application rules.
See [Actions](/guide/actions) for the corresponding `adjust()` implementation.

<span id="synchronize-or-refresh"></span>

## sync()

```html
<button type="button" @click="$server.sync()" :disabled="$server.busy">
  Refresh
</button>
```

`sync(): Promise<RequestResult>` sends current writable edits and renders the
island without invoking an action. Fresh `viewData()` can query new data. It does
not rerun the controller or `mount()`, or automatically save a record.

Actions queue in order; pending synchronization coalesces. Each island processes
one request at a time. [Events](/guide/events) can trigger another island's
refresh; [`x-interact:poll`](/directives/poll) schedules periodic refreshes.

<span id="show-queued-and-executing-work"></span>

## pending()

```html
<button
  type="button"
  @click="$server.call('save')"
  :disabled="$server.pending('save')"
>
  Save
</button>
```

`pending()` becomes true immediately for matching accepted queued or executing
work. Ordinary action buttons remain repeatable unless explicitly disabled;
[`x-interact:submit`](/directives/submit) protects forms automatically.

| Query                                       | Matches                                          |
| ------------------------------------------- | ------------------------------------------------ |
| `pending()`                                 | All accepted requests, including transfers       |
| `pending('save')`                           | Any invocation of `save`                         |
| `pending('remove', 42)`                     | `remove` with the complete argument tuple `[42]` |
| `pending(null)`                             | Synchronization requests                         |
| `pending({ action: 'remove', args: [42] })` | Equivalent object selector                       |

Omitting `action` matches all requests; `null` matches synchronization. Argument
filters require a named action and its complete argument tuple. Scalar types are
preserved, so `42` and `'42'` differ. Object key order is ignored. Unsent model
debounce timers do not count.

## loading()

```html
<span role="status" x-show="$server.loading('save')" x-cloak>Saving…</span>
```

`loading()` accepts the same targets as `pending()`, with a 200 ms default delay.
The object form can set a delay from 0 to 60,000 whole milliseconds:

```html
<span
  role="status"
  x-show="$server.loading({ action: 'remove', args: [42], delay: 500 })"
  x-cloak
  >Removing…</span
>
```

The delay controls presentation only. It includes continuously matching queued
and executing work; overlapping matching requests keep the indicator visible.
Equivalent queries share timers. Resources are released when matching work ends
or the island disappears. Add `[x-cloak] { display: none !important; }` to the
stylesheet when using `x-cloak`.

## busy

`busy` is true while a request executes. It excludes queued work and drives the
island root's `aria-busy`. Form submission protection also covers queueing time.

<span id="show-unsynchronized-drafts"></span>

## dirty

`dirty` is true for unsynchronized edits, unfinished transfers, and failed file
selections. It does not describe whether database changes were saved. Accepted
state and staged files can still be unsaved application data.

<span id="validation-feedback-and-request-failures"></span>

## errors

`errors` maps fields to message lists. PHP supplies these through
[`setErrors()`](/guide/forms#seterrors). A renderable HTTP 422 updates field
feedback without pausing the island. FormHelper can read the entity's original
validation errors separately.

```html
<ul role="alert">
  <template
    x-for="(message, index) in ($server.errors.title || [])"
    :key="index"
  >
    <li x-text="message"></li>
  </template>
</ul>
```

## error

```html
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

`error` is `null` or `{ code, message, status, recovery }`. See
[Error handling](/guide/failures) for failure categories and recovery. Ordinary
handlers do not need `.catch()` markup.

## paused

`paused` is true after a request failure pauses automatic updates. Local editing
continues. Explicit recovery depends on the failure's `recovery` value; failed
actions are never automatically retried.

<span id="export-the-current-writable-draft"></span>

## exportDraft()

`exportDraft(): string` returns formatted JSON for all current writable values,
including synchronized values. It excludes props, server-owned state, files and
upload references, snapshots, and runtime metadata. It does not persist data.

For a user-controlled copy field:

::: code-group

```html [HTML]
<label for="draft-export">Copy draft</label>
<textarea id="draft-export" readonly :value="$server.exportDraft()"></textarea>
```

```php [FormHelper]
<?= $this->Form->control('draftExport', [
    'type' => 'textarea',
    'label' => 'Copy draft',
    'readonly' => true,
    ':value' => '$server.exportDraft()',
]) ?>
```

:::

Writable data may be sensitive. Offer copying intentionally; see
[recovery](/guide/failures#recovery).

<span id="file-transfer-feedback-and-controls"></span>

## uploads

For a declared upload property named `document`:

```html
<progress
  max="100"
  aria-label="Upload progress"
  x-show="$server.uploads.document.pending"
  :value="$server.uploads.document.progress"
></progress>
<button type="button" @click="$server.uploads.document.cancel()">
  Cancel transfer
</button>
```

Each field exposes `pending`, `progress`, `files`, `cancel(id?)`, `remove(id?)`,
and `retry(id?)`. Progress is 0–100 or `null`. File items expose `id`, `name`,
`size`, `status`, and `progress`. Status is `queued`, `uploading`, `ready`, or
`failed`. Omit an ID to target the field's applicable items.

Cancellation does not guarantee PHP stopped processing. Retry transfers files;
it never replays a failed save. Accepted files are PHP uploaded-file objects.
See [File uploads](/guide/uploads) for staging behavior and limits.

<span id="inspect-an-outcome-in-javascript"></span>

## Request results

`call()` and `sync()` return `{ ok: true }` after an accepted render, or
`{ ok: false, error }` after a request failure. An accepted HTTP 422 can contain
validation errors, so `ok` does not prove a save succeeded.

Inside a registered Alpine method:

```js
async save() {
  const result = await this.$server.call('save');
  if (!result.ok) console.log(result.error.code);
}
```

Use `this.$server` in JavaScript methods and `$server` in template expressions.
See [Alpine registration](/guide/alpine#custom-javascript). Removed islands settle
waiting requests with `cancelled` and do not emit new error UI. Aborting fetch
does not cancel server execution.
