---
title: 'PHP API index'
description: Find PHP declarations, lifecycle hooks, rendering, and application integration.
search: false
---

<span id="api-and-lifecycle"></span>
<span id="php-api-and-lifecycle"></span>
<span id="initial-render"></span>
<span id="update"></span>

# PHP API index

PHP contracts are documented with their related features.

## Declarations

| API                   | Documentation                  |
| --------------------- | ------------------------------ |
| `Island`, `#[Render]` | [Islands](/guide/islands)      |
| `#[Prop]`, `#[State]` | [Properties](/guide/state)     |
| `#[Action]`           | [Actions](/guide/actions)      |
| `#[Upload]`           | [File uploads](/guide/uploads) |

## Mounting

[`InteractHelper::render()` and helper options](/guide/islands#mounting).
[Loading modes and placeholders](/guide/lazy-loading).

## Lifecycle

[Initial render, updates, and hooks](/guide/lifecycle).

## Pagination

[`#[Pagination]`, `PaginationState`, `paginate()`, and native helper templates](/guide/pagination).

## Upload properties

[Declaration, multiple files, and field limits](/guide/uploads#upload).

## Hooks and helpers

[Table access: fetchTable(), getTableLocator(), and setTableLocator()](/guide/islands#table-access).

[Lifecycle hooks](/guide/lifecycle), [setErrors()](/guide/forms#seterrors),
[errors()](/guide/forms#errors), [dispatch()](/guide/events#dispatch), and
[events()](/guide/events#events), [redirect()](/guide/redirects-and-downloads), and
[downloadUrl()](/guide/redirects-and-downloads#downloads).

## Streaming

[`stream()`, `streamElement()`, `streamEvent()`, and `#[Action(stream: true)]`](/guide/streaming).

## Binding and actions

[Writable properties](/guide/state#state), [model binding](/directives/model),
and [actions](/guide/actions).

## Validation errors

[Message shapes, normalization, and clearing](/guide/forms#custom-and-nested-errors).

## Nesting and events

[Child identity](/guide/nesting) and [browser events](/guide/events).

## Application integration

[Rendering and container services](/reference/rendering) and
[custom update endpoints](/security#custom-endpoints).

## Testing

[`IslandTestTrait`, fluent interactions, and assertions](/guide/testing).

## Realtime

[`subscriptions()`, `Subscription`, and the publisher shortcuts](/guide/realtime)
connect authorized islands to an external provider. Subscription-only leases and
provider credentials remain separate from signed action snapshots.
