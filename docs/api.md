# API and lifecycle

## Declarations

| API                                                   | Contract                                                      |
| ----------------------------------------------------- | ------------------------------------------------------------- |
| `AsIsland(name, template: ..., element: ...)`         | Declare an alias and exactly one native template or element   |
| `Prop`                                                | Public, typed, mount-only parameter                           |
| `State`                                               | Public, typed, browser-visible state that only PHP may change |
| `State(writable: true)`                               | Explicitly accept browser edits                               |
| `Action`                                              | Expose a public application method                            |
| `InteractHelper::render(alias, props: ..., key: ...)` | Mount initial HTML inside a normal page                       |

Props and state accept strings, integers, finite floats, booleans, nullable variants, and arrays of JSON-compatible values. Arbitrary objects, entities, resources, and unsafe prototype keys are rejected. Never put secrets in transported state.

Each island has a single owned root, a `div` by default. `AsIsland(..., tag: 'section')` selects a semantic root. Supported roots are `div`, `section`, `article`, `aside`, `main`, `nav`, `header`, `footer`, and `fieldset`. Nested placeholders retain the declared root tag.

## Lifecycle

Initial rendering constructs a fresh island, assigns props, authorizes, runs `mount()`, then obtains view data and renders.

Updates verify the signed snapshot/session/scope, reconstruct a fresh island, restore state, validate browser edits and action arguments, authorize, run `updated($paths)`, invoke the exposed action if any, and render again. `mount()` does not run again.

`viewData()` returns additional template variables. It cannot shadow props, state, or the reserved `errors` variable. These values are not serialized automatically. Controller view variables are not inherited by an island.

Public methods without `Action`, including lifecycle methods, cannot be invoked by the browser. Actions accept explicitly typed JSON-compatible arguments.

## Binding and actions

| Attribute                       | Behavior                                                     |
| ------------------------------- | ------------------------------------------------------------ |
| `data-interact-model="title"`   | Update the local draft; defer network requests               |
| `data-interact-update="input"`  | Send after a typing pause; default debounce is 250 ms        |
| `data-interact-debounce="400"`  | Configure an input debounce in milliseconds                  |
| `data-interact-update="blur"`   | Send on focus leaving the control                            |
| `data-interact-update="change"` | Send on the native change event                              |
| `data-interact-submit="save"`   | Flush current controls and call an action on form submission |
| `data-interact-click="next"`    | Flush controls and call an action on click                   |

Use dotted paths for nested arrays, such as `form.address.city`. Native checkboxes, radio groups, selects, multiple selects, numeric state, and textareas are supported. File binding is deferred.

The Alpine `$server` bridge exposes `state`, `busy`, `dirty`, `errors`, `error`, `sync()`, and `call(action, ...args)`. All paths use the same request engine.

Synchronization is not persistence. Save explicitly through your application's normal CakePHP model/service methods. Use `setErrors()` for application validation feedback; failed validation returns renderable HTTP 422 feedback.

## Nesting and events

Give repeated children stable keys. A parent refresh preserves matching children and their drafts rather than rerunning their mount lifecycle. Changing a child's props or alias requires a new key. Duplicate sibling keys are errors. Removed children dispose listeners, timers, and browser requests.

Use `dispatch('article-saved', ['id' => $id])` from PHP to queue a native browser event after a successful response. An explicit Alpine listener may refresh the parent:

```html
<section @article-saved.stop="$server.sync()">
  <!-- Nested editors -->
</section>
```

Events carry browser-visible, untrusted data and are not a durable business-operation queue.

Each island has one request in flight. Later edits remain local; pending syncs coalesce and actions queue in order. Responses preserve edits made after a request began. Actions are never automatically retried.

Navigation and URL-bound state are phase 2. Ordinary CakePHP links continue to navigate normally.

## Application integration

The plugin registers `Registry`, `SnapshotCodec`, and `Factory` through CakePHP's container and preserves application-provided definitions. Island constructors can receive application services; these dependencies are never serialized. The demo uses `LocatorAwareTrait::fetchTable()` for Tables and named finders for domain queries.

Override `Factory::createView()` to supply a `TemplateView` subclass with application helpers. `RendererInterface` is the runtime's markup boundary. Keep adapters on these public contracts and explicit island state/actions rather than introducing form-specific behavior into transport or snapshot verification.

A future FormCompose adapter can use this layer to synchronize conditional fields and model-derived choices. Form declarations, validation, and persistence remain application or adapter responsibilities; that adapter and navigation are outside the first phase.
