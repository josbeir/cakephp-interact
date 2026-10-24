---
title: 'x-interact:ignore'
description: Preserve JavaScript-managed markup during server-rendered updates.
---

<span id="protect-a-widget-s-markup"></span>

# x-interact:ignore

`x-interact:ignore` prevents server updates from changing an existing element's
attributes and contents.

```html
<div id="orders-chart" x-interact:ignore>
  <canvas role="img" aria-label="Daily orders"></canvas>
</div>
```

Use it for markup managed by a chart, editor, map, or enhanced input. Other island
content still updates. Put the directive below the island root.

<span id="keep-alpine-behavior-active"></span>

## Alpine behavior

Alpine still initializes and updates bindings inside the element:

```html
<div x-interact:ignore x-data="{ expanded: false }">
  <button type="button" @click="expanded = !expanded">Toggle details</button>
  <p x-show="expanded">Local content.</p>
</div>
```

Alpine's `x-ignore` prevents Alpine initialization. `x-interact:ignore` only
controls Interact's server HTML updates.

Ordinary local Alpine state already survives updates to retained elements;
it does not need `x-interact:ignore`. Use protection when a third-party library
owns the markup. See [Alpine and Interact](/guide/alpine#template-expressions).

<span id="connect-a-custom-input"></span>

## Custom inputs

Keep the modelable wrapper outside the ignored markup:

```html
<div
  x-data="countryPicker"
  x-modelable="selection"
  x-interact:model.change="country"
>
  <div x-interact:ignore>
    <!-- Library-managed input. -->
  </div>
</div>
```

The application registers `countryPicker`, exposes `selection`, and declares
writable `country` state. Keep server-rendered validation errors outside the
ignored region too. See the complete [enhanced select](/demo#widgets).

<span id="update-a-chart-without-replacing-it"></span>

## Data updates

DOM protection does not prevent library updates through JavaScript. Watch PHP
state from an Alpine wrapper and call the library's update method. Copy the
accepted data before passing it to a library that mutates its inputs, and release
the instance in the wrapper's `destroy()` hook. See
[custom JavaScript](/guide/alpine#custom-javascript) for registration and lifetime.

<span id="recreate-or-remove-the-widget"></span>

## Replacement and removal

A changed HTML ID or element type replaces the protected region. PHP can also
remove it. Keep IDs stable to preserve a widget; change an ID deliberately to
recreate it. Use Alpine `destroy()` to release the library instance, including
any overlays it created outside the wrapper.

Protected option markup also stays unchanged. Update options through the
library's API or replace the keyed region.

<span id="supported-declaration"></span>

## Options

The directive accepts no expression or modifiers, including `.self`. Root
placement and invalid declarations fail at initialization. Keep nested islands
outside ignored regions. DOM protection does not authorize requests or validate
widget values.
