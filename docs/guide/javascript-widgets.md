---
title: JavaScript widgets
description: Preserve widget-owned DOM and connect application Alpine wrappers to island state.
---

# Keep JavaScript widgets working through updates

An editor, chart, or enhanced select often builds its own markup. Give that region
to JavaScript with `x-interact:ignore`, while PHP keeps rendering the surrounding
island.

For the PHP-to-JavaScript data mapping, `$server` scope, and JavaScript file
registration, start with [Alpine and `$server`](/guide/alpine).

For focused usage variations, see [`x-interact:ignore`](/directives/ignore) and
[`x-interact:model`](/directives/model#custom-alpine-inputs).

## See what protection changes

Both panels below render the **same PHP value**. After an update, the normal panel
changes while the protected panel retains its original HTML:

::: code-group

```php [Island]
<?php
declare(strict_types=1);

// src/Island/MorphExample.php
namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Shows how one PHP render updates normal markup while preserving a protected region.
 */
#[AsIsland('morph-example', element: 'Interact/morph_example')]
class MorphExample extends Island
{
    #[State]
    public int $count = 0;

    /**
     * Render the next count in both regions.
     */
    #[Action]
    public function advance(): void
    {
        $this->count++;
    }
}
```

```php [Element]
<?php
declare(strict_types=1);

/**
 * templates/element/Interact/morph_example.php
 *
 * @var int $count
 */
?>
<section>
    <p>Normal region: <?= $count ?></p>
    <div x-interact:ignore>
        <p>Ignored region: <?= $count ?></p>
    </div>
    <button type="button" @click="$server.call('advance')">Update from PHP</button>
</section>
```

```php [Mount]
<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<?= $this->Interact->render('morph-example') ?>
```

:::

The example assumes the [installation](/installation) is complete. Render the
mount call in an ordinary controller template; the element is the island's own
markup.

For a complete runnable comparison, open **Morph protection** at `/playground`.
Each click changes the normal panel's count and color; the ignored panel stays as
it was. **Recreate ignored region** changes its HTML ID, replacing the boundary
with current PHP markup.

:::: details Complete visual comparison

::: code-group

<<< @/../tests/test_app/src/Island/MorphProtection.php [Island]

<<< @/../tests/test_app/templates/element/Interact/morph_protection.php [Element]

:::

::::

Protection applies to the marked element's attributes **and** descendants. PHP
can still remove the region, and a changed key or element type replaces it.
Keep a stable, unique HTML ID when the widget should survive updates.

`x-interact:ignore` protects server morphs. Alpine's `x-ignore` instead prevents
Alpine from initializing a subtree. Ordinary Alpine expressions inside an
Interact-protected region can still react and update their own DOM.

Put protection around the widget's markup, below the island root. Keep
server-rendered validation messages, conditional content, and other islands
outside that boundary.

## Connect a widget value to PHP

An Alpine wrapper is an application JavaScript object registered with
`Alpine.data()`. With the Composer bundle, register it through `window.Alpine`
in an external script's `alpine:init` listener. The
[Alpine guide](/guide/alpine#access-server-from-a-javascript-file) shows the
JavaScript file and layout order; custom behavior does not require rebuilding
Interact. Applications that own Alpine can register wrappers in their existing
bundle instead.

`x-data="enhancedSelect"` creates an instance for that element: Alpine calls its
`init()` when the element appears and `destroy()` when it disappears.

The registration is reusable; each instance runs in its element's Alpine scope.
An instance attached inside an island can access that island's `$server`, including
`this.$server` in its methods. `$server` resolves to the nearest containing island
and is unavailable outside islands.

Use this object to initialize and destroy your chosen library. Expose its editable
JSON value with `x-modelable`, then bind it with the same `x-interact:model` used
by native controls:

```html
<div
  x-data="enhancedSelect"
  x-modelable="value"
  x-interact:model.change="country"
>
  <div x-interact:ignore>
    <!-- Select rendered with CakePHP's FormHelper. -->
  </div>
</div>
```

Here `value` is the wrapper's local property, and `country` is the island's
`#[State(writable: true)]` property. Interact initializes the wrapper from PHP,
captures user edits, and applies accepted PHP values back to it. No hidden input
or separate `$server` callback is required.

The modelable expression must be a property path such as `value` or
`selection.country`. Use JSON-compatible values, not library instances, DOM nodes,
or dates. Keep the library instance outside Alpine's reactive state.

Choose timing in the same place as a native model:

| Binding                                | Custom wrapper behavior                                       |
| -------------------------------------- | ------------------------------------------------------------- |
| `x-interact:model`                     | Keep edits local until an action or `$server.sync()`          |
| `x-interact:model.change`              | Synchronize when the exposed value changes                    |
| `x-interact:model.live.debounce.400ms` | Synchronize after the exposed value stops changing for 400 ms |
| `x-interact:model.blur`                | Synchronize when focus leaves the wrapper                     |

For `.change`, expose a new value when the widget commits a selection. Moving
focus between controls inside a `.blur` wrapper does not submit it. Standard
[native model rules](/guide/state) still apply to native controls.

Interact captures the current value before an action, even if Alpine has not
flushed its reactive effects yet. Newer edits survive an in-flight response.
Server-originated value changes do not trigger another synchronization request.
Application validation and persistence remain server responsibilities.

## Try an enhanced select

The **JavaScript select widget** example at `/playground` uses
[Tom Select](https://tom-select.js.org/). Search for a country, confirm it, reset
from PHP, and refresh the surrounding content. Removing and restoring the widget
exercises its destruction and initialization.

The example uses a native FormHelper select. Its choices and initial value come
from PHP; the Alpine wrapper owns the library instance. Validation errors render
outside the ignored subtree and describe the generated control accessibly.

The demo keeps Interact's Composer runtime and exposes `window.Alpine`. A separate
application bundle contains Tom Select and the wrapper; its entry point registers
the wrapper in an `alpine:init` listener before Interact starts Alpine. Only the
widget code and its library need that application build. Load its script before
Interact's runtime and include the library's stylesheet.

If your application already owns Alpine, install `tom-select`, register the wrapper
before `Alpine.start()`, and include its stylesheet:

::: details Application-owned Alpine registration

```ts
import Alpine from 'alpinejs';
import interact from '@josbeir/cakephp-interact';
import 'tom-select/dist/css/tom-select.default.css';
import { registerEnhancedSelect } from './enhanced-select';

Alpine.plugin(interact);
registerEnhancedSelect(Alpine);
Alpine.start();
```

Follow the [application-owned Alpine setup](/installation#application-owned-alpine)
including disabling Interact's automatic script.

:::

The wrapper below belongs to your application; copy and adapt it to your library
and field names.

:::: details Complete enhanced-select example

::: code-group

<<< @/../tests/test_app/src/Island/EnhancedSelect.php [Island]

<<< @/../tests/test_app/templates/element/Interact/enhanced_select.php [Element]

<<< @/../tests/test_app/resources/ts/enhanced-select.ts [JavaScript]

<<< @/../tests/test_app/resources/ts/widgets.ts [Registration]

```php [Mount]
<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<?= $this->Interact->render('enhanced-select') ?>
```

:::

::::

The registration entry uses the existing `window.Alpine`; it never imports or
starts another Alpine instance. Interact itself does not include Tom Select or
any other widget library.

## Refresh display-only widgets

A chart can preserve its canvas while displaying new server data. It does not
need `x-modelable`: there is no editable value to send back to PHP.

The data comes from the **PHP island's declared property**, not the JavaScript
file. Interact exposes `#[State] public array $chartData` as
`$server.state.chartData`. Without `writable: true`, PHP controls the value and the
browser cannot submit edits to it. Controller variables and `viewData()` do not
automatically appear in `$server.state`.

The cooperating files are:

| File                                         | Responsibility                                                    |
| -------------------------------------------- | ----------------------------------------------------------------- |
| `src/Island/SalesChart.php`                  | Own the data and refresh action                                   |
| `templates/element/Interact/sales_chart.php` | Attach `x-data="salesChart"` inside the island                    |
| `resources/js/sales-chart.js`                | Register the Alpine object; create, update, and destroy the chart |

For an application using Chart.js, install `chart.js` in your application and
follow the [application-owned Alpine setup](/installation#application-owned-alpine),
including disabling Interact's automatic script. This variation uses an
application-owned bundle to import Chart.js and Alpine together. You can also keep
the Composer runtime and register the wrapper through `window.Alpine` in an
`alpine:init` listener, as in the enhanced-select example; bundle the chart library
and wrapper without importing or starting Alpine again.

This example starts with fixed
sample totals; each refresh increments them. Replace those increments with data
loaded through your application's Table finders, initializing it in `mount()`
and replacing `$this->chartData` in the refresh action.

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Supplies server-owned totals to an application-managed chart.
 *
 * Save as src/Island/SalesChart.php.
 */
#[AsIsland('sales-chart', element: 'Interact/sales_chart')]
class SalesChart extends Island
{
    /**
     * Chart.js data, exposed for display without accepting browser edits.
     *
     * @var array{labels: list<string>, datasets: list<array{label: string, data: list<int>}>}
     */
    #[State]
    public array $chartData = [
        'labels' => ['Monday', 'Tuesday'],
        'datasets' => [
            ['label' => 'Orders', 'data' => [12, 18]],
        ],
    ];

    /**
     * Simulate refreshed totals for this small example.
     */
    #[Action]
    public function refreshChart(): void
    {
        $this->chartData['datasets'][0]['data'][0]++;
        $this->chartData['datasets'][0]['data'][1]++;
    }
}
```

```php [Element]
<?php
declare(strict_types=1);

/**
 * templates/element/Interact/sales_chart.php
 *
 * @var \Interact\View\TemplateView $this
 */
?>
<div x-data="salesChart">
    <div x-interact:ignore>
        <canvas x-ref="canvas" role="img" aria-label="Daily orders"></canvas>
    </div>
    <button type="button" @click="$server.call('refreshChart')">Refresh chart</button>
</div>
```

```js [JavaScript]
// resources/js/sales-chart.js
import Chart from 'chart.js/auto';

export function registerSalesChart(Alpine) {
  Alpine.data('salesChart', () => {
    let chart;

    return {
      init() {
        const copy = (data) => JSON.parse(JSON.stringify(data));
        chart = new Chart(this.$refs.canvas, {
          type: 'bar',
          data: copy(this.$server.state.chartData),
        });

        this.$watch('$server.state.chartData', (data) => {
          chart.data = copy(data);
          chart.update();
        });
      },

      destroy() {
        chart?.destroy();
      },
    };
  });
}
```

```js [Registration]
// Your application's JavaScript entry point.
import Alpine from 'alpinejs';
import interact from '@josbeir/cakephp-interact';
import { registerSalesChart } from './sales-chart';

Alpine.plugin(interact);
registerSalesChart(Alpine);
Alpine.start();
```

```php [Mount]
<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<?= $this->Interact->render('sales-chart') ?>
```

:::

The watcher belongs in the **JavaScript file**, inside the Alpine object's
`init()`, after creating the chart. The file is loaded once; `x-data` creates an
instance for each mounted element. That element is inside the island, so its
`this.$server` and watcher resolve to the owning island's state.

When PHP changes `$this->chartData`, Interact updates `$server.state.chartData`.
The watcher passes a copy to the existing Chart.js instance and calls `update()`;
`x-interact:ignore` preserves its DOM. Copying allows the library to manage its
data without mutating reactive island state. Chart.js documents
[updating an existing chart](https://www.chartjs.org/docs/latest/developers/updates.html);
other libraries have equivalent update APIs. [Polling](/guide/polling) can invoke
the same refresh action.

## Keep ownership explicit

The wrapper's `init()` creates the widget, listens for user changes, and watches
its exposed value to apply server changes through the library's API. Use silent
library writes to avoid feedback loops. Its `destroy()` releases the instance and
any library-owned resources, including overlays outside the wrapper.

Protected option markup does not automatically change when PHP renders new
choices. Update choices explicitly through your wrapper and the library's API,
or replace the keyed boundary to create a fresh widget. A server reset of the
**selected value** is already handled by the custom model binding.

A disabled native control is excluded from model capture. For a custom wrapper,
a `disabled` attribute likewise suspends capture and automatic synchronization;
use the library's disable API too when the user must not interact with it. Model
bindings remain scoped to their nearest island.

Ignore protection is a DOM ownership tool, not an authorization boundary. Validate
submitted choices on the server even when the widget only displays allowed ones.
