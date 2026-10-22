---
title: 'Chart updates'
description: Update an existing Chart.js instance when PHP state changes.
---

# Chart updates

This chart reads server-owned data and updates its existing Chart.js instance
after a PHP action.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

## Assets

Install Chart.js in your application and copy its browser bundle:

```sh
npm install chart.js
mkdir -p webroot/vendor/chart
cp node_modules/chart.js/dist/chart.umd.js webroot/vendor/chart/
```

Load the library and registration before Interact's runtime:

```php
<?= $this->Html->script('/vendor/chart/chart.umd.js', ['defer' => true]) ?>
<?= $this->Html->script('SalesChart', ['defer' => true]) ?>
<?= $this->fetch('script') ?>
```

Keep automatic script loading enabled. No extra Alpine instance or runtime build
is required.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/SalesChartIsland.php [Island]

<<< @/../tests/docs/app/templates/island/sales_chart.php [Template]

<<< @/../tests/docs/app/webroot/js/sales-chart.js [JavaScript]

```php [Mount]
<?= $this->Interact->render('SalesChart') ?>
```

:::

## Behavior

PHP declares `chartData` with `#[State]`, so JavaScript can read it through
`$server.state.chartData`. The watcher copies accepted data into the chart and
calls `update()`. Copying prevents the library from mutating reactive island state.

`x-interact:ignore` preserves the canvas; the library can still update it.
`destroy()` releases the chart. The sample action increments fixed totals;
replace that operation with application data loaded through Table finders.
Display-only charts need no `x-modelable`. See [Alpine](/guide/alpine) for scope
and [polling](/directives/poll) for periodic refreshes.
