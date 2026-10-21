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

| API                     | Documentation                  |
| ----------------------- | ------------------------------ |
| `Island`, `#[AsIsland]` | [Islands](/guide/islands)      |
| `#[Prop]`, `#[State]`   | [Properties](/guide/state)     |
| `#[Action]`             | [Actions](/guide/actions)      |
| `#[Upload]`             | [File uploads](/guide/uploads) |

## Mounting

[`InteractHelper::render()` and helper options](/guide/islands#mounting).

## Lifecycle

[Initial render, updates, and hooks](/guide/lifecycle).

## Upload properties

[Declaration, multiple files, and field limits](/guide/uploads#upload).

## Hooks and helpers

[Lifecycle hooks](/guide/lifecycle), [setErrors()](/guide/forms#seterrors),
[errors()](/guide/forms#errors), [dispatch()](/guide/events#dispatch), and
[events()](/guide/events#events).

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
