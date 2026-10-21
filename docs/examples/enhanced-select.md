---
title: 'Enhanced select'
description: Integrate Tom Select with Alpine modelable state and PHP validation.
---

# Enhanced select

This example uses Tom Select for country selection. PHP can validate or reset
the value without replacing the widget.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

## Assets

Install the library in your application and copy its browser assets:

```sh
npm install tom-select
mkdir -p webroot/vendor/tom-select
cp node_modules/tom-select/dist/js/tom-select.base.min.js webroot/vendor/tom-select/
cp node_modules/tom-select/dist/css/tom-select.default.css webroot/vendor/tom-select/
```

In your layout's `<head>`, before Interact's script block:

```php
<?= $this->Html->css('/vendor/tom-select/tom-select.default.css') ?>
<?= $this->Html->script('/vendor/tom-select/tom-select.base.min.js', ['defer' => true]) ?>
<?= $this->Html->script('country-picker', ['defer' => true]) ?>
<?= $this->fetch('script') ?>
```

Keep Interact's automatic script enabled. The registration uses its existing
`window.Alpine`; it does not rebuild or start another runtime.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/CountryPicker.php [Island]

<<< @/../tests/docs/app/templates/element/Interact/country_picker.php [Element]

<<< @/../tests/docs/app/webroot/js/country-picker.js [JavaScript]

```php [Mount]
<?= $this->Interact->render('country-picker') ?>
```

:::

## Behavior

`x-modelable` exposes `selection`; `x-interact:model.change` connects it to PHP.
The ignored region contains the library's markup. Error messages remain outside
it. PHP reset updates the widget silently, avoiding a request loop.

The library instance stays outside reactive state. `destroy()` releases it when
the wrapper disappears. Production styling should also associate field messages
with the widget's generated accessible control, as shown by the broader demo.
