---
title: 'Alpine and Interact'
description: Use Alpine for local state, events, bindings, and transitions; use Interact to connect them to PHP.
---

<span id="read-island-data-with-alpine-and-javascript"></span>
<span id="alpine"></span>

# Alpine and Interact

Interact is built on [Alpine.js](https://alpinejs.dev/start-here). Use Alpine
directly for browser behavior: local state, events, bindings, and transitions.
Interact adds [`$server`](/reference/server) and `x-interact:*` directives to
connect that behavior to PHP state, actions, and server-rendered HTML.

The standard Composer bundle includes Alpine and starts it for you. You can use
the examples below without installing Alpine separately or writing a JavaScript
entry point. Complete [Quickstart](/getting-started) first.

<span id="read-a-php-value-in-the-browser"></span>
<span id="know-which-data-reaches-javascript"></span>
<span id="use-inline-expressions-in-your-template"></span>
<span id="distinguish-browser-drafts-from-php-rendering"></span>

## Template expressions

Inside the Quickstart counter's template, add this local details panel and use
the action button below in place of the original **Add one** button:

```html
<div x-data="{ expanded: false }">
  <button type="button" @click="expanded = !expanded" :aria-expanded="expanded">
    Toggle details
  </button>
  <div x-show="expanded" x-transition.opacity.duration.180ms x-cloak>
    <p x-text="'Current count: ' + $server.state.count"></p>
  </div>
  <button
    type="button"
    @click="$server.call('increment')"
    :disabled="$server.busy"
  >
    Add one
  </button>
</div>
```

This uses the Quickstart island's declared `count` state and `increment()` action.
Keep its PHP-rendered count and error feedback. Add
`[x-cloak] { display: none !important; }` to your application stylesheet to hide
the panel until Alpine initializes.

| Syntax                                             | Owner and behavior                                                     |
| -------------------------------------------------- | ---------------------------------------------------------------------- |
| `x-data="{ expanded: false }"`                     | Alpine creates local state for this element and its descendants.       |
| `@click="expanded = !expanded"`                    | Alpine handles a click and changes local state.                        |
| `:aria-expanded="expanded"`                        | Alpine binds an HTML attribute to an expression.                       |
| `x-show="expanded"`                                | Alpine toggles visibility without removing the element.                |
| `x-transition.opacity.duration.180ms`              | Alpine animates the `x-show` visibility change.                        |
| `x-text="'Current count: ' + $server.state.count"` | Alpine displays an expression that reads Interact's current PHP state. |
| `@click="$server.call('increment')"`               | Alpine handles the click; Interact sends the PHP action request.       |
| `:disabled="$server.busy"`                         | Alpine disables the button using Interact's request feedback.          |

`@click` is shorthand for Alpine's `x-on:click`; `:aria-expanded` is shorthand for
`x-bind:aria-expanded`. Use the shorthand consistently in your templates.
For conditional CSS, use the same binding syntax: `:class="{ expanded: expanded }"`.

Open the panel, then press **Add one**. Toggling makes no request. Incrementing
sends a request and updates both the PHP-rendered count and the `x-text` value.
The panel stays open: Interact preserves local Alpine state on retained elements
during server HTML updates. Removing or replacing that local component creates
new state on its next initialization.

`x-transition` here animates visibility controlled by `x-show`. It does not
automatically animate arbitrary HTML inserted or removed by a PHP update.
For more variations, see Alpine's [state guide](https://alpinejs.dev/essentials/state),
[event handlers](https://alpinejs.dev/directives/on), and
[transitions](https://alpinejs.dev/directives/transition).

## Local state and PHP state

Keep menus, tabs, expanded panels, and other browser-only values in `x-data`.
Declare state in PHP when PHP needs to read or change it. Reading
`$server.state.count` stays reactive as Interact accepts responses; copying it
into `x-data`, such as `{ initialCount: $server.state.count }`, initializes a
separate local value once.

Props and state are browser-visible. `viewData()` supplies PHP-only variables;
see [Properties](/guide/state). Browser visibility does not make a PHP property
writable: model edits require `#[State(writable: true)]`, and PHP actions require
`#[Action]`.

### Input binding

Use Alpine's [`x-model`](https://alpinejs.dev/directives/model) when an input
only changes local Alpine state. Use [`x-interact:model`](/directives/model)
when it edits a writable PHP draft. For example, `x-model="filter"` refers to
a value declared in `x-data`, while `x-interact:model="search"` refers to
declared writable PHP state. The latter's modifiers control when edits reach PHP;
its default defers edits until the next action or explicit synchronization.

Choose one model binding per native control. For a custom Alpine component,
[`x-modelable` with `x-interact:model`](/directives/ignore#custom-inputs) connects
its local value to a PHP draft. Synchronizing a draft does not save it.

### Events and request feedback

Use Alpine's [`$dispatch`](https://alpinejs.dev/magics/dispatch) for local browser
events and `@event` listeners to receive them. Interact can also dispatch browser
events from accepted PHP actions; see [Events](/guide/events).

Use Alpine bindings to display Interact's feedback, for example
`x-show="$server.loading('save')"` or `:disabled="$server.pending('save')"`.
These expressions observe requests; they do not send them. See
[`$server`](/reference/server) for feedback queries, state, and action methods.

## CSP build

The Composer bundle uses [Alpine's CSP build](https://alpinejs.dev/advanced/csp).
Object literals, property access,
arithmetic, ternaries, assignments, and method calls work in attributes without
`unsafe-eval`. Inline method definitions and arrow callbacks are not supported;
put them in an external `Alpine.data()` registration.

External files use normal JavaScript syntax. Applications owning their Alpine
instance can choose standard Alpine instead, subject to its `unsafe-eval`
requirement. See [installation](/installation#application-owned-alpine).

<span id="keep-server-scoped-to-its-island"></span>
<span id="access-server-from-a-javascript-file"></span>

## Custom JavaScript

The Composer runtime exposes `window.Alpine`. Register behavior in a plain
external script before the runtime starts:

```js
// webroot/js/counter-tools.js
document.addEventListener('alpine:init', () => {
  window.Alpine.data('counterTools', () => ({
    increment() {
      return this.$server.call('increment');
    },
  }));
});
```

Load it before the layout's Interact script block, with `defer`:

```php
<?= $this->Html->script('counter-tools', ['defer' => true]) ?>
<?= $this->fetch('script') ?>
```

Attach it inside the counter island:

```html
<div x-data="counterTools">
  <button type="button" @click="increment()">Add one</button>
</div>
```

Inside methods, use `this.$server`. In template expressions, use `$server`.
A registration can be reusable; each instance resolves to its nearest containing
island. Outside an island, `$server` is unavailable.

Keep automatic script loading enabled for this setup. Adding application
JavaScript does not require rebuilding Interact or importing another Alpine
instance. The [Quickstart counter](/getting-started) provides `increment()`.

<span id="react-to-changing-server-data"></span>

## Watching state and cleaning up

Use `init()` for initialization and `$watch()` for accepted state changes.
Use `destroy()` to release library instances and resources. Keep library instances
outside reactive state, and return promises from request methods.

The [demo's enhanced select](/demo#widgets) includes a complete registration and
lifecycle handling. See [DOM protection](/directives/ignore) for display-only widgets.

<span id="alternative-application-owned-alpine"></span>

## Application-owned Alpine

If an application already starts Alpine, install Interact on that instance and
register application objects before the existing `Alpine.start()`. Disable
Interact's automatic script for this integration. The complete setup is in
[Installation](/installation#application-owned-alpine).
