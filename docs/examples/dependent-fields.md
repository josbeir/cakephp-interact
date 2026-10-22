---
title: 'Dependent fields'
description: Reload city options when a country changes and validate the submitted combination.
---

# Dependent fields

This form loads cities for the selected country and rejects a city from another
country.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

Create and seed the `cities` table using the schema tab or your application's
normal migrations and data.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/DependentSelectsIsland.php [Island]

<<< @/../tests/docs/app/templates/island/dependent_selects.php [Template]

<<< @/../tests/docs/app/src/Model/Table/CitiesTable.php [Table]

<<< @/../tests/docs/app/schema/cities.sql [Schema]

```php [Mount]
<?= $this->Interact->render('DependentSelects') ?>
```

:::

## Behavior

Country changes synchronize immediately. `updated()` clears the previous city;
`viewData()` loads new choices through `inCountry`. The confirmation action
checks membership through `selection`, so forged combinations also fail.

Rendered options are a convenience. The action remains responsible for checking
the submitted values. Apply the same pattern to conditional fields and validate
required values even when the browser previously hid a control.
