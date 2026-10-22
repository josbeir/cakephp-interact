---
title: x-interact:persist
description: Retain explicit layout DOM, Alpine state, and compatible embedded islands between enhanced page visits.
---

# x-interact:persist

`x-interact:persist` retains an existing layout region when the destination contains a compatible region with the same literal key.

```html
<div x-interact:persist="player">
  <audio src="/episodes/current.mp3" controls></audio>
</div>
```

The actual nodes are reused, preserving local Alpine state, media, listeners, focused controls, and scrollable regions. The destination still runs its normal CakePHP request. Persistence applies only to [enhanced navigation](/directives/navigate), not a full browser reload.

## Embedded layout islands

```php
<aside x-interact:persist="notebook">
    <?= $this->Interact->render(
        'notebook',
        props: ['workspaceId' => $workspaceId],
        key: 'layout-notebook',
    ) ?>
</aside>
```

This assumes an application-declared `notebook` island and authorized `$workspaceId`. Put the wrapper inside the layout's body, outside any page-owned island. The document's `html`, `head`, and `body` elements cannot themselves be persistent regions. Keys must be unique, nonempty, and literal. Regions cannot nest and the directive accepts no modifiers.

Every incoming island mounts and authorizes independently on the server. Reuse requires matching island name, key, declared prop values, endpoint, scope, URL prefix, and session binding. Changing props or keys, or removing the region, disposes the old region and initializes fresh destination markup.

## State and ownership

Mutable drafts, Alpine state, polling, uploads, and pending requests survive a compatible visit. Incoming markup for a retained region stays uninitialized, preventing duplicate clients and loading triggers. URL-bound properties follow the destination query while unrelated drafts survive.

Retained islands keep their existing signed snapshots and originating CakePHP route defaults. Every later update still authorizes against current application permissions. Persistence does not grant access or silently retarget action routing to the destination controller.

When a region is replaced or absent, Alpine cleanup disposes its island clients, requests, timers, controls, observers, and widgets. Page-owned islands always mount fresh, including on Back/Forward. See the [tested example](/examples/navigation).
