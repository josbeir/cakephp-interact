---
title: 'Reusable child inputs'
description: Build a resource picker with reactive props and bind multiple child values to a parent draft.
---

# Reusable child inputs

This form binds a resource picker's selected ID and a date range to its own writable
draft. Changing the category refreshes the picker while preserving its unrelated
notes. Child PHP changes and parent resets update the shared values.

Complete [installation](/installation) first. The runnable demo is at `/composition`;
follow the [demo setup](/examples#run-the-demo). It uses the seeded Resources Table
and finders already supplied by the demo. Copying this example into an application
requires adapting `TestApp` namespaces to `App` and supplying those resources and
finders, or adapting the queries to your own model.

## Parent form

The parent owns the category and draft. `review()` checks the selected resource's
category membership on the server. It demonstrates validation without saving a record;
an application supplies its own persistence action.

::: code-group

<<< @/../tests/test_app/src/Island/Composition/OrderIsland.php [Parent]

<<< @/../tests/test_app/templates/island/Composition/order.php [Template]

```php [Mount]
<?= $this->Interact->render('Composition/Order') ?>
```

:::

## Resource picker

The picker receives a reactive category, owns its search and notes, and exposes
only `value` to its parent's `data.resourceId` binding. Its `updated()` hook clears
the selection when the category changes. `choose()` checks membership before
accepting an ID. The parent validates independently when reviewing the draft.

::: code-group

<<< @/../tests/test_app/src/Island/Composition/PickerIsland.php [Picker]

<<< @/../tests/test_app/templates/island/Composition/picker.php [Template]

<<< @/../tests/test_app/src/Model/Table/ResourcesTable.php [Table]

:::

## Multiple bound values

The date input exposes `start` and `end`. The parent maps them to `data.startsAt`
and `data.endsAt`. Editing updates the parent's draft immediately; the renderless
`preset()` action publishes both PHP values through the same bindings. The parent's
`clear()` action sends cleared values back to both children.

::: code-group

<<< @/../tests/test_app/src/Island/Composition/DateRangeIsland.php [Dates]

<<< @/../tests/test_app/templates/island/Composition/date_range.php [Template]

:::

Bindings use deferred parent synchronization here. Changing the picker category
uses an explicit `.change` binding on the parent control; child search requests
remain independent. See [nested islands](/guide/nesting#synchronization-timing)
for live, change, blur, and debounce options.

## Deferred children

Open `/composition?loading=manual` to change the parent category before loading
the picker. Parent input changes retain the placeholder; loading later uses the
latest verified props. The same contracts apply to lazy and deferred initialization.
