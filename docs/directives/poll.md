---
title: 'x-interact:poll'
description: Refresh islands on an interval, pause while hidden or offscreen, and stop requests when PHP reports completion.
---

# x-interact:poll

Refresh a status panel periodically. Put one polling declaration inside an
[existing island](/getting-started):

```html
<section x-interact:poll.5s>
  <!-- Status rendered by the island. -->
</section>
```

After five seconds, Interact calls `$server.sync()` and renders the **whole
owning island**, including fresh `viewData()`. It does not rerun the controller
or `mount()`. The declaration's location does not limit which part is refreshed.

## Change the interval

```html
<section x-interact:poll.1500ms>
  <!-- Refresh every 1.5 seconds. -->
</section>
```

The default is 5000 ms. Use one positive whole-number duration ending in `ms`
or `s`, from 1 through 2,147,483,647 milliseconds.

The first tick waits a full interval. Each later interval starts after the
previous expression settles, so slow requests do not overlap or create a backlog.

## Call a PHP action

An expression can invoke a declared action instead of plain synchronization:

```html
<section x-interact:poll.10s="$server.call('refreshStatus')">
  <!-- Current job status. -->
</section>
```

The island must expose `refreshStatus()` with `#[Action]`. Polling repeats this
call, so use it for a repeatable application operation such as reading job
status. Starting work and saving records should be explicit application decisions.

## Stop requests when PHP reports completion

Declare server-owned state and let the action update it:

```php [Inside the island class]
#[State]
public bool $running = true;

#[State]
public int $progress = 0;

/**
 * Advance a simulated job and publish whether another status request is needed.
 */
#[Action]
public function refreshStatus(): void
{
    $this->progress = min(100, $this->progress + 25);
    $this->running = $this->progress < 100;
}
```

Import `State` and `Action` from `Interact\Attribute`. This fragment belongs in
an island class; the [complete job example](/guide/polling#poll-a-php-action-until-completion)
includes its declaration, element, mount, and restart control. A real application
reads its authorized job's status instead of incrementing a simulated value.

Guard the call with that state:

```html
<section
  x-interact:poll.5s="$server.state.running && $server.call('refreshStatus')"
>
  <!-- Current progress rendered by PHP. -->
</section>
```

When PHP sets `$running = false`, the accepted response updates
`$server.state.running`. Later ticks evaluate the condition locally and make no
request. No separate PHP stop method is required. If an explicit restart action
sets it to `true`, a later tick resumes requests.

## Let the user pause and resume

Use local Alpine state for a presentation preference:

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

Changing the checkbox is local and needs no PHP property. For a job panel,
combine the conditions:
`autoRefresh && $server.state.running && $server.call('refreshStatus')`.
Resuming the preference then cannot restart a completed job.

## Poll only while visible

```html
<section x-interact:poll.5s.visible>
  <!-- A dashboard panel that refreshes while onscreen. -->
</section>
```

All polling pauses while the browser document is hidden. `.visible` additionally
requires the declaration's element to intersect the viewport. Put it on the
actual panel, rather than an empty marker.

Returning to visibility starts a fresh interval, without replaying missed ticks.
Hiding a tab, scrolling away, or changing a condition does not cancel PHP work
already accepted by the server.

## Preserve edits and feedback

Before evaluating a polling expression, Interact skips the tick if the owning
island has unsynchronized drafts, scheduled model updates, queued or executing
requests, validation messages, unfinished or failed file selections, or a request
failure that paused automatic work. Skipped ticks are not queued.

Manual actions and explicit synchronization remain available subject to the
normal [failure recovery rules](/guide/failures). Newer edits made during an
in-flight poll retain the usual draft and focus protections.

## Supported declaration and lifetime

Use one poller per island. Nested islands may each own a separate poller. Parent
refreshes preserve matching keyed children; their polling lifetimes remain
independent.

The directive accepts a duration, optional `.visible`, and an optional Alpine
expression. An omitted or blank expression synchronizes. Unknown or repeated
modifiers and multiple owned declarations fail at initialization. There are no
separate polling methods on `$server`.

Asynchronous custom functions must return their promise so polling waits before
starting the next interval. `$server.call()` and `$server.sync()` already return
promises. Expression errors use normal island failure feedback. Removing the
declaration or island releases its timers and visibility resources.

For complete tested PHP and element implementations, see the
[clock and job recipes](/guide/polling).
