---
title: 'Alpine'
description: Use local Alpine state, CSP expressions, and custom JavaScript inside islands.
---

<span id="read-island-data-with-alpine-and-javascript"></span>

# Alpine

Interact includes Alpine.js for browser-side behavior inside islands. Use Alpine
for local UI state and [`$server`](/reference/server) for PHP state and actions.

<span id="read-a-php-value-in-the-browser"></span>
<span id="know-which-data-reaches-javascript"></span>
<span id="use-inline-expressions-in-your-template"></span>
<span id="distinguish-browser-drafts-from-php-rendering"></span>

## Template expressions

```html
<div x-data="{ expanded: false }">
  <button type="button" @click="expanded = !expanded">Toggle details</button>
  <p x-show="expanded" x-text="'Count: ' + $server.state.count"></p>
</div>
```

This assumes an island with a declared `count` property. `expanded` is local;
changing it sends no request. `$server.state.count` reads the current island value.

Props and state are browser-visible. `viewData()` supplies PHP-only variables;
see [Properties](/guide/state). Copying a value into `x-data`, such as
`{ initialCount: $server.state.count }`, initializes a local value once. Reading
`$server.state.count` directly remains reactive.

## CSP build

The Composer bundle uses Alpine's CSP build. Object literals, property access,
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
