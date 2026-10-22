---
title: 'Search and pagination'
description: Search database rows through a Table finder and paginate without transporting the result list.
---

# Search and pagination

This listing searches resource titles and renders ten rows per page. Its query
and page are reloadable through URLs such as `?q=resource&page=2`.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

The schema tab includes 25 sample rows for SQLite. The finder uses SQLite's
`INSTR` function for literal substring matching. Adapt that expression to your
database or an existing application finder.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/ResourceSearchIsland.php [Island]

<<< @/../tests/docs/app/templates/island/resource_search.php [Template]

<<< @/../tests/docs/app/src/Model/Table/ResourcesTable.php [Table]

<<< @/../tests/docs/app/schema/resources.sql [Schema]

```php [Mount]
<?= $this->Interact->render('ResourceSearch') ?>
```

:::

## Behavior

Typing sends the search after 300 ms and resets the page to one. Native
`paginate()` bounds the requested page against the filtered count; empty results
remain on page one. Ordinary `PaginatorHelper` links update the owning island and
retain GET URLs that work on reload and without JavaScript.

Back and Forward restore the search and page together. `updated()` respects an
explicitly supplied page. Rows and counts remain in the native paginated result;
only the search and page are transported.

The Table returns a composable query; the island chooses pagination. See
[pagination](/guide/pagination) for configuration, sorting, page sizes, and custom
helper templates. The larger [demo listing](/examples#demo-catalog) adds filters,
allowlisted sorting, and page-size controls.
