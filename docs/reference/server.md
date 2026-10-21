---
title: '$server'
description: Read island state, call PHP actions, synchronize drafts, show activity and validation feedback, and control file transfers from Alpine.
---

# $server

`$server` is the browser bridge to the nearest owning island. Use it in ordinary
Alpine expressions to read data, call PHP actions, refresh, and show feedback.

## Read data and call an action

With the PHP counter from [your first island](/getting-started), add this to its
element:

```html
<p x-text="'Count: ' + $server.state.count"></p>
<button
  type="button"
  @click="$server.call('increment')"
  :disabled="$server.busy"
>
  Add one
</button>
```

The PHP class declares `#[State] public int $count = 0` and an
`#[Action] increment()` method. Interact exposes the count, sends the action
request, and updates the browser state and server-rendered HTML together.
The island root already initializes Alpine; these bindings need no extra `x-data`.

## State and scope

`$server.state` contains declared props and state, including current writable
drafts. A PHP `$name` property is available as `$server.state.name`. The
[Alpine guide](/guide/alpine#know-which-data-reaches-javascript) maps PHP declarations
to browser access and explains why entities and `viewData()` stay PHP-only.

Use [`x-interact:model`](/directives/model) for editable controls. Reading a prop
or server-owned state does not make it writable. Model drafts update immediately
in the browser; PHP-rendered values change after an accepted server response.

`$server` belongs to the **nearest containing island**. Nested islands use their
own bridge. Two instances of the same island have independent state; `$server`
does not become available outside islands or create shared application state.

## Call PHP actions with arguments

```html
<button type="button" @click="$server.call('adjust', 5)">Add five</button>
```

The island must declare an action such as `adjust(int $amount)` with `#[Action]`;
the [action guide](/guide/actions#pass-an-argument) includes that implementation.
Arguments are JSON-compatible values, passed after the action name. Only declared
actions are callable. PHP still validates allowed values and authorizes any
records identified by arguments.

Each island runs one request at a time. Actions queue in order and capture the
current draft when preparing their request. Different islands work independently.
Actions are never automatically retried.

## Synchronize or refresh

```html
<button type="button" @click="$server.sync()" :disabled="$server.busy">
  Refresh
</button>
```

`sync()` sends writable drafts and rerenders the owning island without invoking
an action. Fresh `viewData()` can load new application data. It does not rerun the
controller or `mount()`, and synchronization never implies database saving.
Pending synchronization requests coalesce.

To refresh after another island saves, listen inside the receiving island:

```html
<section @article-saved.window="$server.sync()">
  <!-- This island's listing. -->
</section>
```

The saving island dispatches the application event. The
[event examples](/guide/nesting#refresh-another-island) cover dispatching,
parent/sibling listeners, matching records, and what a refresh updates. For
periodic refreshes, use [`x-interact:poll`](/directives/poll).

## Show queued and executing work

```html
<button
  type="button"
  @click="$server.call('save')"
  :disabled="$server.pending('save')"
>
  Save
</button>
<span role="status" x-show="$server.loading('save')" x-cloak>Saving…</span>
```

This assumes a declared `save()` action. `pending()` matches accepted queued or
executing work immediately. `loading()` uses the same matching but waits 200 ms
by default, so fast requests do not flash an indicator. Include
`[x-cloak] { display: none !important; }` in your stylesheet.

| Query                                               | Matches                                         |
| --------------------------------------------------- | ----------------------------------------------- |
| `$server.pending()`                                 | Any accepted queued or executing request        |
| `$server.pending('save')`                           | Any invocation of `save`                        |
| `$server.pending('remove', 42)`                     | `remove` with exactly the argument tuple `[42]` |
| `$server.pending(null)`                             | Synchronization without an action               |
| `$server.pending({ action: 'remove', args: [42] })` | The equivalent object selector                  |

`loading()` accepts all these selectors. Its object form can override the delay:

```html
<span
  role="status"
  x-show="$server.loading({ action: 'remove', args: [42], delay: 500 })"
  x-cloak
>
  Removing this article…
</span>
```

Use this inside an island that exposes `remove()`. It only reflects removal of
record 42. Matching preserves scalar types and ignores object key order;
`42` and `'42'` are different arguments. Argument selectors require a named action
and its complete argument tuple. Omitting `action` matches all requests; `null`
matches synchronization.

The visual delay must be an integer from 0 to 60,000 ms. It never delays a request
or form protection. Neither helper counts an unsent model debounce timer.
Unqualified queries include file transfers; action selectors continue to match
PHP actions. Equivalent delayed queries share their timing while work remains
continuously pending, including time queued behind other work. Timing resources
are released when that interval ends or the island disappears.

`busy` means a request is **executing**; it does not include time queued behind
another request. An island root's `aria-busy` follows that executing state.
[`x-interact:submit`](/directives/submit) provides automatic per-form protection
and form-level `aria-busy` while a submission waits or runs.

## Show unsynchronized drafts

```html
<p x-show="$server.dirty">Your edits have not reached the server yet.</p>
```

`dirty` means the browser has unsynchronized edits, unfinished transfers, or
failed file selections. It does **not** tell you whether a database record has
been saved. Accepted drafts may still be unsaved application data.

## Validation feedback and request failures

For an island with a `title` field, browser-accessible application messages are
available as lists in `$server.errors`:

```html
<ul role="alert" aria-label="Title errors">
  <template
    x-for="(message, index) in ($server.errors.title || [])"
    :key="index"
  >
    <li x-text="message"></li>
  </template>
</ul>
```

CakePHP forms can render entity errors through FormHelper instead. Use the normal
[forms and validation flow](/guide/forms); the bridge does not run validation.

Request failures appear separately in `$server.error`:

```html
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

`error` is `null` or `{ code, message, status, recovery }`. A null `status` means
there was no readable HTTP response. `recovery` is `retry`, `reload`,
`authenticate`, or `none`. It describes the possible next step; it never triggers
an automatic action. Ordinary handlers need no `.catch()` callback.

`paused` becomes true after a request failure pauses automatic updates. User
editing continues. Use the [failure guide](/guide/failures) for explicit recovery,
status categories, and the `interact:error` event. A valid renderable HTTP 422
instead supplies validation messages and does not pause the island.

## Inspect an outcome in JavaScript

Both `call()` and `sync()` return `Promise<RequestResult>`. In a registered
Alpine object's method:

```js
async save() {
  const result = await this.$server.call('save');
  if (!result.ok) {
    console.log(result.error.code);
  }
}
```

An accepted render returns `{ ok: true }`; a request failure returns
`{ ok: false, error }`. A renderable HTTP 422 is an accepted render, so `ok` does
not prove validation or persistence succeeded. Use application state or events
to announce a successful save.

The [Alpine registration example](/guide/alpine#access-server-from-a-javascript-file)
shows where to place methods in an external script. There, use `this.$server`;
inside template expressions, use `$server`. A reusable registration can live
outside the template, while each `x-data` instance still gets its owning island.

## Export the current writable draft

For an intentional user-controlled export, render a readonly textarea:

```php [Inside the island element]
<?= $this->Form->control('draftExport', [
    'type' => 'textarea',
    'label' => 'Copy current draft',
    'readonly' => true,
    ':value' => '$server.exportDraft()',
]) ?>
```

`exportDraft()` returns formatted JSON for all writable values, including already
synchronized values. It excludes props, server-owned state, files and upload
references, snapshots, and runtime metadata. It does not persist data in browser
storage. See the [recovery recipe](/guide/failures#keep-the-draft-and-offer-a-choice)
for when to offer it and how to handle sensitive writable values.

## File transfer feedback and controls

For a declared `#[Upload]` field named `document`:

```html
<progress
  max="100"
  x-show="$server.uploads.document.pending"
  :value="$server.uploads.document.progress"
  aria-label="Document upload progress"
></progress>
<button type="button" @click="$server.uploads.document.cancel()">
  Cancel transfer
</button>
```

`$server.uploads.document` exposes `pending`, `progress`, and `files`, plus
`cancel(id?)`, `remove(id?)`, and `retry(id?)`. Progress is 0–100 or `null` when
unavailable. File items expose `id`, `name`, `size`, `status`, and `progress`;
status is `queued`, `uploading`, `ready`, or `failed`.

Cancel stops browser transfer work without guaranteeing PHP stopped processing.
Removal accepts a browser item ID or a PHP `TemporaryUpload::id`; omit it to
clear the field. Retry transfers files only and never repeats a failed save.

Accepted files are available as PHP uploaded-file variables. The bridge exposes
transfer feedback and controls, not file objects or image processing. See
[file uploads](/guide/uploads) for binding, server validation, field limits, and
saving through an entity and Table.

## Member reference

| Member                      | Contract                                                    |
| --------------------------- | ----------------------------------------------------------- |
| `state`                     | Reactive props and state, including current writable drafts |
| `call(action, ...args)`     | Queue a declared PHP action with JSON-compatible arguments  |
| `sync()`                    | Synchronize writable drafts and rerender without an action  |
| `pending(target?, ...args)` | Match queued or executing work immediately                  |
| `loading(target?, ...args)` | Match the same work after a configurable visual delay       |
| `busy`                      | A request is executing                                      |
| `dirty`                     | Local edits or file selections need synchronization         |
| `errors`                    | Application messages as a field-to-message-list map         |
| `error`                     | Structured request failure, or `null`                       |
| `paused`                    | Automatic updates are paused after a request failure        |
| `exportDraft()`             | Export current writable values as formatted JSON            |
| `uploads`                   | Per-field transfer feedback and controls                    |

Removing an island releases its browser timers, listeners, observers, and
in-flight browser work. Aborting a fetch does not promise cancellation of PHP
execution. The [browser reference](/reference/browser#scheduling-and-dom-behavior)
describes shared request scheduling and DOM preservation.
