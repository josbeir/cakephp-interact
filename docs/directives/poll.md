---
title: 'x-interact:poll'
description: Refresh a PHP island periodically, conditionally, or only while visible.
---

# x-interact:poll

`x-interact:poll` refreshes an island at a regular interval.

```html
<section x-interact:poll.5s>
  <!-- Island content. -->
</section>
```

The default is five seconds. Each tick calls `$server.sync()` and rerenders the
whole island, including fresh `viewData()`. The controller and `mount()` do not
run again. See the [job polling example](/examples/polling).

<span id="change-the-interval"></span>

## Intervals

Use one whole-number duration ending in `ms` or `s`:

```html
<section x-interact:poll.1500ms>...</section>
```

Intervals range from 1 through 2,147,483,647 milliseconds. The first tick waits
one interval. Each subsequent interval starts after the previous expression
settles, preventing overlapping polls and missed-tick backlogs.

<span id="call-a-php-action"></span>

## Actions

```html
<section x-interact:poll.10s="$server.call('refreshStatus')">...</section>
```

The island must expose `refreshStatus()` with `#[Action]`. Polling actions repeat;
use them for repeatable operations such as reading job status.

<span id="stop-requests-when-php-reports-completion"></span>

## Conditions and PHP completion

```html
<section
  x-interact:poll.5s="$server.state.running && $server.call('refreshStatus')"
>
  <!-- Current progress. -->
</section>
```

Declare `running` as server-owned state. When PHP sets it to `false`, the response
updates `$server.state.running` and subsequent ticks make no request. The timer
still evaluates the condition locally. Setting it back to `true` permits later
requests; no separate PHP stop method is needed.

<span id="let-the-user-pause-and-resume"></span>

## Pause and resume

```html
<section
  x-data="{ autoRefresh: true }"
  x-interact:poll.5s="autoRefresh && $server.sync()"
>
  <button
    type="button"
    @click="autoRefresh = !autoRefresh"
    x-text="autoRefresh ? 'Pause' : 'Resume'"
  ></button>
</section>
```

For a job panel, combine the conditions:
`autoRefresh && $server.state.running && $server.call('refreshStatus')`.
Resuming the local preference then cannot restart a completed job.

<span id="poll-only-while-visible"></span>

## Visibility

```html
<section x-interact:poll.5s.visible>...</section>
```

Hidden browser tabs pause all polling. `.visible` also requires the marked
element to intersect the viewport. Put the directive on the actual panel.
Returning to visibility starts a fresh interval without a catch-up burst.
Visibility changes do not cancel PHP requests already accepted.

<span id="preserve-edits-and-feedback"></span>

## Edits and failures

Ticks skip unsynchronized edits, queued model synchronization, outstanding
requests, validation messages, unfinished or failed file selections, and paused
request failures. Skipped ticks are not queued. Newer edits made during a request
retain normal draft and focus protections.

Manual interactions follow the normal [error recovery rules](/guide/failures).
Asynchronous polling functions must return their promise. Expression failures
use normal island request feedback.

<span id="supported-declaration-and-lifetime"></span>

## Options and lifetime

Use one poller per island. Nested islands own independent pollers. Removing a
declaration or island releases its timer and visibility resources.

The directive accepts one duration, optional `.visible`, and an optional Alpine
expression. A blank expression synchronizes. Unknown/repeated modifiers and
multiple declarations owned by one island are initialization errors.
