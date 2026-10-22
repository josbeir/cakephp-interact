---
title: Lazy resource count
description: Defer a finder query until an embedded island loads.
---

# Lazy resource count

This example counts matching resources after its placeholder becomes visible.
The initial page does not run the resource query.

Use the [installation setup](/installation) and the `ResourcesTable` and schema
from [search and pagination](/examples/search). Save the files at the paths in
their docblocks.

::: code-group

<<< @/../tests/docs/app/src/Island/ResourceCountIsland.php [Island]

<<< @/../tests/docs/app/templates/island/resource_count.php [Template]

```php [Mount]
<?= $this->Interact->render('ResourceCount', loading: 'lazy') ?>
```

:::

## Load on demand

For a custom placeholder with a load button, use `manual`:

::: code-group

<<< @/../tests/docs/app/templates/element/Loading/resource_count.php [Placeholder]

```php [Mount]
<?= $this->Interact->render(
    'ResourceCount',
    props: ['search' => 'Resource 01'],
    loading: 'manual',
    placeholder: 'Loading/resource_count',
) ?>
```

:::

The placeholder receives `$search` from the declared prop. Its button initializes
the island; the loaded template's button refreshes the count normally.
See [lazy loading](/guide/lazy-loading) for modes, authorization, and recovery.
The larger demo at `/loading` also demonstrates independent reports, hidden
panels, parent refreshes, and removal while loading.
