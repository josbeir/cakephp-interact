---
title: 'x-interact:ignore'
description: Preserve widget-owned markup across island renders while keeping Alpine reactive and allowing explicit removal or replacement.
---

# x-interact:ignore

Preserve an existing element's attributes and descendants when Interact applies
new server HTML. This is useful when a JavaScript library owns a chart, editor,
map, or enhanced input.

## Protect a widget's markup

Inside an existing island, mark the element the library owns:

```html
<div id="orders-chart" x-interact:ignore>
  <canvas role="img" aria-label="Daily orders"></canvas>
</div>
<p>Other content here still receives server updates.</p>
```

After initialization, a normal island refresh preserves this element's current
attributes and children. It still updates surrounding markup. Put the directive
**below the island root**, around only the library-owned region.

Open `/playground` to try the **Morph protection** example: PHP renders the same
count in two colored panels, but only the ordinary panel changes on refresh.
The [widget guide](/guide/javascript-widgets#see-what-protection-changes) includes
the complete island and element.

## Keep Alpine behavior active

Alpine still initializes and updates expressions inside the protected region:

```html
<div x-interact:ignore x-data="{ expanded: false }">
  <button type="button" @click="expanded = !expanded" :aria-expanded="expanded">
    Toggle details
  </button>
  <p x-show="expanded">Local Alpine state stays interactive.</p>
</div>
```

Interact skips **server morphing** for this region. Alpine's `x-ignore` has a
different purpose: it prevents Alpine from initializing the subtree. Use
`x-interact:ignore` when a widget needs both preserved markup and Alpine behavior.

## Connect a custom input

Keep the wrapper's exposed value and validation feedback outside the protected
subtree. For an application-registered `countryPicker` wrapper exposing
`selection` and an island with writable `country` state:

```php [Inside the element]
<div
    x-data="countryPicker"
    x-modelable="selection"
    x-interact:model.change="country"
>
    <div x-interact:ignore>
        <?= $this->Form->control('country', [
            'type' => 'select',
            'label' => 'Country',
            'options' => $countries,
            'value' => $country,
        ]) ?>
    </div>
</div>
<?= $this->Form->error('country') ?>
```

Your wrapper creates the library instance, exposes user changes through
`selection`, and applies accepted PHP values through the library's API. Supply
`$countries` through `viewData()` and use the application's FormHelper context
for its field errors. The complete
[Tom Select example](/guide/javascript-widgets#try-an-enhanced-select) shows
registration, accessible errors, and cleanup.

Do not put server-rendered errors inside the ignored region: they would also be
preserved instead of receiving new PHP output.

## Update a chart without replacing it

DOM protection does not freeze the JavaScript library. Declare server-owned
chart data with `#[State]`, then watch it in the Alpine wrapper's `init()`:

```js
this.$watch('$server.state.chartData', (data) => {
  chart.data = JSON.parse(JSON.stringify(data));
  chart.update();
});
```

Here `chart` is an application-created Chart.js instance kept outside reactive
state. Copying the data lets the library manage it without mutating island state.
The [complete chart recipe](/guide/javascript-widgets#refresh-display-only-widgets)
shows the PHP declaration, wrapper, element, registration, and mount. Display-only
widgets need no `x-modelable` binding.

## Recreate or remove the widget

Protection applies to an existing matching element. PHP can still remove it or
replace it by changing its stable HTML ID or element type. For an island that
declares integer `generation` state:

```php [Inside the element]
<div id="orders-chart-<?= (int)$generation ?>" x-interact:ignore>
    <!-- Widget-owned markup. -->
</div>
```

Keep the ID unchanged to preserve the widget. An explicit action can increment
`generation` when it should be recreated. If the region contains an Alpine
wrapper, use its `destroy()` to release library resources and `init()` to create
the replacement. Cleanup must include any overlays placed outside the wrapper.

Changes to protected PHP option markup are also ignored. Update a widget's
choices through its library API or replace its keyed boundary deliberately.

## Supported declaration

Use `x-interact:ignore` without an expression or modifiers. It protects both the
element's attributes and its descendants. There is no `.self` variation.
Root placement, expressions, and modifiers fail at initialization.

Keep nested islands and server-rendered feedback outside a protected region.
This directive defines DOM ownership; it does not authorize requests or validate
submitted widget values.
