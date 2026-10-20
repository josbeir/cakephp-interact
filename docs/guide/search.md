---
title: Search and pagination
description: Follow a model-backed library search with debounced input, filters, allowlisted sorting, and bounded pagination.
---

# Search and pagination

Filter a library of 1,200 resources without sending the library as browser state.
[Run the demo](/examples), then open `/search`. Type `accessible labels`, change
the topic or difficulty, choose a sort order, and move through the result pages.

This guide follows that working example. Its `Resources` table, schema, and
seed data belong to the demo; use your application's own Table and named finders
when adapting it.

## Send the search draft after typing

The island declares a writable search term and a server-controlled page:

```php [ResourceSearch — property declarations]
#[State(writable: true)]
public string $search = '';

#[State]
public int $page = 1;
```

Inside its FormHelper form, the element sends the draft after a short typing pause:

```php [Inside the resource search element]
<?= $this->Form->control('search', [
    'type' => 'search',
    'label' => 'Search resources',
    'value' => $search,
    'maxlength' => 200,
    'x-interact:model.live' => 'search',
]) ?>
```

Import `State` in the class and start the FormHelper form as in
[the state guide](/guide/state). Every search word must match a title,
description, or topic. Clearing the input returns the full library.

## Query only the current page

`ResourceSearch::listing()` calls a named Table finder with explicit filters:

```php [ResourceSearch — query composition]
return $this->resources()->find(
    'listing',
    search: $this->search,
    category: $this->category,
    level: $this->level,
    sort: $this->sort,
);
```

The Table owns matching, allowed filters, and ordering. The island chooses when
to count and execute that query in `viewData()`:

```php [ResourceSearch — inside viewData()]
$query = $this->listing();
$total = $query->count();

return [
    'resources' => $query->limit($this->pageSize)->page($this->page)->all(),
    'total' => $total,
    // The full example also supplies choices and pagination display values.
];
```

`resources` and `total` are render data. The snapshot carries only filters and
pagination choices, regardless of how many database rows match.

## Reset and bound pagination

When a filter, sort, or page size changes, `updated()` resets the page to one.
The `goTo(int $page)` action clamps navigation to the current result count;
an empty result still has a valid first page.

Buttons pass the requested page as a typed action argument:

```php [Inside the resource search element]
<button type="button" @click="$server.call('goTo', <?= (string)($page + 1) ?>)"
    :disabled="$server.busy">Next page</button>
```

The complete element also disables navigation at the first and last pages.
Page size accepts only 10, 20, or 50. Sort values map to model-owned columns;
a stable ID tie-breaker prevents equal titles from shuffling between pages.

## Read the complete working files

The tabs below import the tested demo files directly. They work together in
the included application, mounted with
`$this->Interact->render('resource-search')`.

:::: details Full search example: island, Table, and element

::: code-group

<<< @/../tests/test_app/src/Island/ResourceSearch.php [Island]

<<< @/../tests/test_app/src/Model/Table/ResourcesTable.php [Table finders]

<<< @/../tests/test_app/templates/element/Interact/resource_search.php [Element]

:::

::::

The demo finder uses SQLite substring expressions with bound values, so `%` and
`_` remain literal search characters. Adapt the matching implementation to your
database and search requirements. For private data, apply an access scope in the
finder and establish the current identity in `authorize()`; the article listing
at `/` demonstrates that pattern.

Filters currently live in island state. They do not update the URL or survive a
full reload. URL-bound state and enhanced navigation are planned for phase 2.
