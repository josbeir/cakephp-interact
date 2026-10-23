---
title: 'Nested islands'
description: Compose independent child islands with reactive props, modelable values, and preserved drafts.
---

<span id="nested-islands-and-events"></span>

# Nested islands

Nested islands keep their own state, requests, and input drafts when a parent
island refreshes.

<span id="give-each-editor-an-identity"></span>

## Rendering children

For a listing whose `viewData()` supplies `$articles`:

```php
<?php foreach ($articles as $article): ?>
    <?= $this->Interact->render(
        'Article/Editor',
        props: ['articleId' => (int)$article->id],
        key: 'article-' . (string)$article->id,
    ) ?>
<?php endforeach; ?>
```

The child declares `articleId` with `#[Prop]`. `Article/Editor` resolves to
`App\Island\Article\EditorIsland` on demand.

## Keys

Keys must be unique among siblings and stable between renders. Use record IDs,
rather than positions in a result list. A matching child keeps its draft and
browser instance when the parent refreshes.

Changing a child's island name, ordinary props, URL prefix, or model-binding configuration
requires a new key. Reactive prop and bound value changes retain the existing child.
Include a revision when
you intentionally want a fresh mount. Removing a child disposes it; showing it
again creates a new instance.

See [JavaScript configuration](/configuration#javascript-configuration) for shared timing defaults.

## Reactive props

`#[Prop(reactive: true)]` lets an accepted parent render update a child input without
remounting the child. Import `Interact\Attribute\Prop`:

```php
#[Prop(reactive: true)]
public string $category = 'cakephp';
```

Pass the value with the ordinary `props` option:

```php
<?= $this->Interact->render(
    'ResourcePicker',
    props: ['category' => $category],
    key: 'resource-picker',
) ?>
```

When the parent's accepted category changes, Interact queues a child refresh. The
child restores its own latest snapshot, receives the new prop, and authorizes the
result before `updated()` and rendering. Its `mount()` hook does not run again.
Ordinary `#[Prop]` inputs retain their mount-only behavior. Reactive props cannot
be written through browser model bindings.

Use `updated()` for application decisions such as clearing an invalid selection:

```php
use Interact\UpdatedPaths;

public function updated(UpdatedPaths $paths): void
{
    if ($paths->has('category')) {
        $this->value = null;
    }
}
```

Unrelated child drafts and local Alpine state survive. An executing child action
finishes once; pending parent inputs apply before subsequent work. Parent and
child requests remain independently authorized.

## Modelable child inputs

`#[State(writable: true, modelable: true)]` exposes a child value that can bind to
writable state on its direct parent. Import `Interact\Attribute\State`:

```php
#[State(writable: true, modelable: true)]
public ?int $value = null;
```

For a parent with writable `$data = ['resourceId' => null]`:

```php
<?= $this->Interact->render(
    'ResourcePicker',
    props: ['category' => $category],
    key: 'resource-picker',
    model: 'data.resourceId',
) ?>
```

The parent supplies the initial value before child authorization and `mount()`.
Child edits update the parent's local draft immediately; parent draft changes
update the child value. Accepted PHP changes flow in both directions, including
renderless responses. An unbound modelable property behaves as ordinary writable state.

The string form requires exactly one modelable property. For multiple values, map
child property names to parent paths:

```php
<?= $this->Interact->render('DateRange', model: [
    'start' => 'data.startsAt',
    'end' => 'data.endsAt',
]) ?>
```

Initialize every parent path explicitly, including nullable values. Its owning
property must be writable. Top-level PHP types and nullability must match; nested
array values must satisfy the child's declared type. Bindings support the normal
JSON value types, including arrays; uploads retain their separate contract.

Only explicitly mapped values cross ownership boundaries. Parent form-level
model inference continues to exclude child controls. Before a parent action,
Interact captures bound child controls, including edits made before blur.

### Synchronization timing

Bindings default to deferred synchronization: edits change the parent draft,
and the next parent action or `sync()` sends them. Configure a binding when the
parent should synchronize automatically:

```php
<?= $this->Interact->render('ResourcePicker', model: [
    'value' => ['path' => 'data.resourceId', 'timing' => 'change'],
]) ?>
```

| Timing   | Parent request                                                                    |
| -------- | --------------------------------------------------------------------------------- |
| `defer`  | Next explicit parent action or synchronization; default                           |
| `live`   | Debounced value changes; `delay` uses the installation default (250 ms initially) |
| `change` | Value changes, including accepted child PHP changes                               |
| `blur`   | Focus leaves the child island                                                     |

`live` accepts a whole-millisecond `delay` from 0 to 60,000. Child search fields
can use their own synchronization timing independently. Equivalent propagated
values do not generate echo requests, and newer drafts survive older responses.

### URL ownership and validation

Configure URL state on the parent value. A bound child property cannot also own a
URL binding. For a shareable selection, bind to a parent scalar such as
`#[State(writable: true, url: true)] public ?int $resourceId = null` with
`model: 'resourceId'`. See [URL state](/guide/url-state).

Each island authorizes its own server requests. A model binding grants no resource
access and does not validate or save application data. The parent action must
validate a selected ID and its membership before using or saving it.

### Loading and navigation

Changing inputs does not initialize a pending lazy, deferred, or manual child.
Its eventual initialization uses the latest verified parent inputs, then newer
local model drafts reconcile through the normal update path. Removing a child
disposes its connections and timers. Compatible persistent regions retain their
connections during enhanced navigation.

The [child inputs example](/examples/child-inputs) includes a reactive resource
picker, multiple date bindings, parent validation, and a runnable mount call.

<span id="announce-a-successful-save"></span>
<span id="refresh-another-island"></span>
<span id="refresh-only-the-matching-article"></span>
<span id="what-a-refresh-updates"></span>

## Events

Use [events](/guide/events) to refresh a parent or sibling after a child saves.
`$server` always resolves to the nearest island, including inside nested markup.

<span id="keep-browser-only-state-local"></span>

## Local Alpine state

Local `x-data` survives ordinary updates while its element is preserved. Use it
for open panels and other browser-only UI preferences. Store PHP-interactive
values in declared properties instead.
