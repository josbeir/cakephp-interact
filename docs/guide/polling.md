---
title: Polling
description: Refresh an island periodically with managed timers, conditional PHP actions, and ordinary Alpine controls.
---

# Polling

Keep a status panel current without making the user press Refresh. Add a polling
declaration inside an [existing island](/getting-started):

```html [Inside the island element]
<section x-interact:poll.5s>
  <!-- Existing island content. -->
</section>
```

After five seconds, Interact calls the owning island's `$server.sync()`. It uses
the normal authorized update request and renders fresh `viewData()`. It does not
rerun the controller or `mount()`. The declaration refreshes the whole owning
island, including content outside the declaration's element.

[Run the demo](/examples) and open `/playground`. The **Server clock** card reads
the server's current time on every render. Its auto-refresh checkbox pauses
requests while keeping the manual refresh button available.

## Choose an interval

`x-interact:poll` defaults to five seconds. Use whole milliseconds or seconds to
change it:

```html
<section x-interact:poll.10s>
  <!-- Refresh every ten seconds. -->
</section>
```

`x-interact:poll.1500ms` is also valid. The first request waits one interval, since
the initial page already contains rendered content. After a polling expression
settles, another full interval passes before the next tick. Slow requests do not
create overlapping polls or a backlog of missed ticks.

## Poll a PHP action until completion

Use the same `$server.call()` expression as an ordinary action button:

```html [Inside the job-progress element]
<section
  x-interact:poll.5s="
    $server.state.running && $server.call('refreshStatus')
  "
>
  <!-- Job progress rendered by the island. -->
</section>
```

Here `running` is declared PHP state, and `refreshStatus()` is declared with
`#[Action]`. Each tick evaluates the current state. Once PHP returns
`running: false`, the expression makes no further requests. An explicit restart
action can set it to true again; polling then continues on a later tick.

Poll actions repeat. In a real job panel, read the authorized job's status in
the action or `viewData()`; keep persistence an explicit application decision.
The demo deliberately simulates progress by advancing 25% per request. It does
not launch a background worker or save anything.

:::: details Complete simulated job example

::: code-group

<<< @/../tests/test_app/src/Island/JobProgress.php [Island]

<<< @/../tests/test_app/templates/element/Interact/job_progress.php [Element]

```php [Page template]
<?= $this->Interact->render('job-progress') ?>
```

:::

::::

## Let the user pause

Combine a local Alpine preference with the existing server bridge:

```html
<section
  x-data="{ autoRefresh: true }"
  x-interact:poll.5s="autoRefresh && $server.sync()"
>
  <label>
    <input type="checkbox" x-model="autoRefresh" />
    Automatically refresh
  </label>
</section>
```

The checkbox is local Alpine state, so it needs no PHP property or server
request. Custom polling expressions use the declaration's current Alpine scope.
If a custom asynchronous function performs the work, return its promise so the
poller waits for completion; `$server.sync()` and `$server.call()` already do.

:::: details Complete clock example

::: code-group

<<< @/../tests/test_app/src/Island/ServerClock.php [Island]

<<< @/../tests/test_app/templates/element/Interact/server_clock.php [Element]

```php [Page template]
<?= $this->Interact->render('server-clock') ?>
```

:::

::::

## Refresh only while visible

Hidden browser tabs pause polling automatically. Returning to the tab starts a
fresh interval, with no catch-up burst. To also pause when the declaration's
element is offscreen, add `.visible`:

```html
<section x-interact:poll.5s.visible>
  <!-- A dashboard panel that refreshes only while onscreen. -->
</section>
```

Viewport visibility observes this element, so put the directive on the visible
panel rather than an empty marker. Hiding a tab, scrolling away, or changing a
condition does not cancel a request already accepted by the server.

## Keep edits and feedback intact

Before evaluating an expression, polling skips the tick when the island has:

- Unsynchronized local drafts, including current model-control values.
- Scheduled model updates, or queued or executing requests.
- Field or form validation messages from an accepted response.
- A request failure that has paused automatic updates.

Skipped ticks are never queued. An explicit interaction remains available to
synchronize edits, clear validation feedback, or recover from a retryable
failure. `dirty` means unsynchronized edits, not unsaved database changes.
Edits made during an in-flight poll retain the existing draft, focus, and
selection protections.

Request failures use `$server.error` and the normal
[explicit recovery flow](/guide/failures). Polling does not retry failed actions,
even when the browser becomes visible again. An expression exception or rejected
promise is reported as a client failure through that same flow.

## Ownership and lifetime

Declare one poller per island. A parent and a nested child may each have their
own declaration; child drafts do not pause a parent's poller. Existing keyed
children retain their state when a parent refreshes.

Removing the declaration or its island releases its timer, visibility listener,
and observer. Server-rendered declaration changes rebind through Alpine's normal
lifecycle. Browser integration and modifier limits are listed in the
[polling reference](/reference/browser#polling).
