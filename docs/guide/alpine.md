---
title: Alpine and $server
description: Read PHP props and state in Alpine expressions and JavaScript files, understand island scope, and call PHP actions.
---

# Read island data with Alpine and JavaScript

Interact supplies `$server` inside each island. It exposes that island's declared
props and state, plus operations such as calling PHP actions. Ordinary Alpine
directives can read it directly; a separate JavaScript file is only needed when
you want reusable JavaScript behavior or a library integration.

## Read a PHP value in the browser

Start with the counter from [your first island](/getting-started). Its PHP class
declares `#[State] public int $count = 0` and an `increment()` action. You can
render its count using either PHP or Alpine in the element:

```php [Element]
<?php
declare(strict_types=1);

/**
 * templates/element/Interact/counter.php
 *
 * @var int $count
 */
?>
<p>Rendered by PHP: <?= $count ?></p>
<p x-text="'Read by Alpine: ' + $server.state.count"></p>
<button type="button" @click="$server.call('increment')">Add one</button>
```

Both show the same count after an accepted update. The PHP expression runs on
the server; `x-text` reads reactive data in the browser. Interact initializes
Alpine on the island root, so these bindings need no additional `x-data`.

## Know which data reaches JavaScript

| PHP declaration                                | PHP element variable | JavaScript access          | Browser may submit edits |
| ---------------------------------------------- | -------------------- | -------------------------- | ------------------------ |
| `#[Prop] public string $salutation`            | `$salutation`        | `$server.state.salutation` | No                       |
| `#[State] public int $count`                   | `$count`             | `$server.state.count`      | No                       |
| `#[State(writable: true)] public string $name` | `$name`              | `$server.state.name`       | Yes                      |
| `viewData()` returns `['article' => $article]` | `$article`           | Not automatically exposed  | No                       |

For example, the [greeting island](/guide/state#bind-a-text-input) declares the
`salutation` prop. Pass it from an existing controller template:

```php [Mount]
<?= $this->Interact->render('greeting', props: ['salutation' => 'Welcome']) ?>
```

Its element can read `$salutation` in PHP or `$server.state.salutation` in an
Alpine expression. Props initialize the island on mount and remain available on
updates; they cannot be changed through browser model bindings.

Props and state contain JSON-compatible values, including scalars and nested
arrays. Both are browser-visible. Keep entities, queries, services, and other
PHP-only data outside snapshots; use `viewData()` for objects needed by the PHP
view. Controller variables are not implicitly inherited: pass values explicitly
at the mount call.

## Keep `$server` scoped to its island

`$server` resolves to the **nearest containing island**. Two counters on one page
have different bridges and independent state. A nested island uses its own bridge.
Outside an island, `$server` is unavailable.

```php
<?= $this->Interact->render('counter', key: 'first') ?>
<?= $this->Interact->render('counter', key: 'second') ?>
```

A reusable JavaScript definition can live in any application module. Its instance
gets an island scope when an element **inside the island** uses `x-data` to attach
it. Registering an Alpine object does not create a global `$server` or share one
island's state with another. Use [island events](/guide/nesting#refresh-another-island)
for explicit communication between islands.

## Access `$server` from a JavaScript file

An Alpine object, sometimes called a wrapper, is registered with `Alpine.data()`.
Alpine creates an instance for each matching `x-data` element. Inside its methods,
access the owning bridge through **`this.$server`**:

::: code-group

```js [JavaScript]
// resources/js/counter-tools.js
export function registerCounterTools(Alpine) {
  Alpine.data('counterTools', () => ({
    get description() {
      return 'Count: ' + this.$server.state.count;
    },

    increment() {
      return this.$server.call('increment');
    },
  }));
}
```

```php [Element]
<?php
declare(strict_types=1);

/**
 * templates/element/Interact/counter.php
 *
 * @var int $count
 */
?>
<div x-data="counterTools">
    <p x-text="description"></p>
    <button type="button" @click="increment()">Add one</button>
</div>
```

```js [Registration]
// Your application's JavaScript entry point.
import Alpine from 'alpinejs';
import interact from '@josbeir/cakephp-interact';
import { registerCounterTools } from './counter-tools';

Alpine.plugin(interact);
registerCounterTools(Alpine);
Alpine.start();
```

:::

This uses the same PHP counter as before; `description` and `increment()` are
JavaScript behavior attached by `x-data="counterTools"`. Follow the
[application-owned Alpine setup](/installation#application-owned-alpine), including
disabling the helper's automatic script. Register behavior before `Alpine.start()`;
do not start Alpine again for each island or render.

For the Composer bundle, load an external registration script before the runtime
and register your object in an `alpine:init` listener using `window.Alpine`.
The [widget guide](/guide/javascript-widgets#try-an-enhanced-select) shows that
alternative without introducing another Alpine instance.

## React to changing server data

Put imperative initialization and watchers in the Alpine object's `init()` method.
For example, after creating a chart there, watch the owning island's declared
`chartData` state:

```js
this.$watch('$server.state.chartData', (data) => {
  chart.data = JSON.parse(JSON.stringify(data));
  chart.update();
});
```

The watcher is JavaScript in your registration file, inside `init()`. The PHP
island owns `$chartData`; Interact sends accepted changes to the browser; the
watcher updates the existing chart. The complete
[chart example](/guide/javascript-widgets#refresh-display-only-widgets) shows the
PHP class, element, JavaScript file, registration, and mount together.

Use `destroy()` to clean up library instances when their owning element disappears.
Use [DOM protection](/guide/javascript-widgets#see-what-protection-changes) when a
library manages its own markup.

## Distinguish browser drafts from PHP rendering

For writable state, model bindings update the browser draft immediately. A plain
`x-interact:model` defers sending that draft until an action or explicit sync:
`x-text="$server.state.name"` can therefore change before PHP receives the name.
A PHP-rendered `<?= h($name) ?>` changes on the next accepted server render.

Use [native model bindings](/guide/state#choose-when-to-send-edits) or
[modelable widgets](/guide/javascript-widgets#connect-a-widget-value-to-php) to
produce editable values. Use `$server.call()` for declared PHP actions and
`$server.sync()` to synchronize without an action. Synchronizing drafts does not
save records automatically.

Keep presentation-only state in ordinary Alpine `x-data`, such as whether a menu
is open. It does not need a PHP declaration or an update request. See the
[browser API reference](/reference/browser) for the complete bridge contract.
