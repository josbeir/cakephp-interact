---
title: 'Search and pagination'
description: Search database rows through a Table finder and paginate without transporting the result list.
---

# Search and pagination

This listing searches resource titles and renders ten rows per page.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

The schema tab includes 25 sample rows for SQLite. The finder uses SQLite's
`INSTR` function for literal substring matching. Adapt that expression to your
database or an existing application finder.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/ResourceSearch.php [Island]

<<< @/../tests/docs/app/templates/element/Interact/resource_search.php [Element]

<<< @/../tests/docs/app/src/Model/Table/ResourcesTable.php [Table]

<<< @/../tests/docs/app/schema/resources.sql [Schema]

```php [Mount]
<?= $this->Interact->render('resource-search') ?>
```

:::

## Behavior

Typing sends the search after 300 ms and resets the page to one. `goTo()` bounds
the requested page against the filtered count. Empty results remain on page one.
Rows and counts come from `viewData()`; only the search and page are transported.

The Table returns a composable query; the island chooses counting and pagination.
The larger [demo listing](/examples#demo-catalog) adds filters, allowlisted sorting,
and page-size controls. These examples keep filters local to the island; URL
synchronization is not implemented.
