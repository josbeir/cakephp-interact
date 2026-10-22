---
title: 'Pagination'
description: Paginate application queries with native CakePHP results and automatically enhanced PaginatorHelper links.
---

# Pagination

`Island::paginate()` paginates an application-owned query using CakePHP's numeric
paginator. Ordinary `PaginatorHelper` links update the owning island, retain native
GET destinations, and work with custom pagination templates.

## Minimal pagination

Declare writable, URL-bound `page` state. Configure `$paginate` and return the native
result from `viewData()`:

```php
<?php
declare(strict_types=1);

namespace App\Island;

use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Renders one page from the application's Resources Table.
 */
class ResourcesIsland extends Island
{
    use LocatorAwareTrait;

    #[State(writable: true, url: true, history: true)]
    public int $page = 1;

    /**
     * @var array<string, mixed> Native numeric pagination defaults.
     */
    protected array $paginate = ['limit' => 20, 'order' => ['Resources.id' => 'asc']];

    /**
     * Fetch request-local results without transporting rows.
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        return ['resources' => $this->paginate($this->fetchTable('Resources')->find())];
    }
}
```

Use native helper calls in `templates/island/resources.php`:

```php
<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface<int, \Cake\Datasource\EntityInterface> $resources
 */
?>
<ul>
    <?php foreach ($resources as $resource): ?>
        <li><?= h($resource->get('title')) ?></li>
    <?php endforeach; ?>
</ul>
<p><?= $this->Paginator->counter('{{start}}–{{end}} of {{count}}') ?></p>
<nav aria-label="Resource pages">
    <ul>
        <?= $this->Paginator->prev('Previous') ?>
        <?= $this->Paginator->numbers() ?>
        <?= $this->Paginator->next('Next') ?>
    </ul>
</nav>
```

Mount with `<?= $this->Interact->render('Resources') ?>`. This example assumes an
application `Resources` Table with a `title` column. The [search example](/examples/search)
includes a tested Table, SQLite schema, template, and mount call.

## Configuration and state

The signature is `paginate(RepositoryInterface|QueryInterface $object, array $settings = []): PaginatedInterface`.
Pass an existing query or Table; resolve Tables with the application's normal locator.
Per-call settings override protected `$paginate` defaults, as in controller pagination.

| State property      | Required                | Behavior                                                   |
| ------------------- | ----------------------- | ---------------------------------------------------------- |
| `page: int`         | Yes                     | Current page; normally defaults to `1`.                    |
| `sort: string`      | With sorting            | Public sort key; `''` uses configured ordering.            |
| `direction: string` | With `sort`             | Native sort direction; normally defaults to `'asc'`.       |
| `limit: int`        | For editable page sizes | Overrides the configured page size; bounded by `maxLimit`. |

Every declared paging field must be writable and URL-bound. Their PHP names are
conventional; `State(url: 'p')` can change a query name without changing `$page`.
Without a `limit` property, `$paginate['limit']` determines the page size.
`history: true` opts into Back/Forward entries through the existing [URL state](/guide/url-state) system.

Native `limit`, `maxLimit`, `order`, `finder`, per-repository settings, and
`sortableFields` are supported. `className` may select `Numeric` or a compatible
numeric paginator subclass. One island can paginate one dataset per request;
`SimplePaginator` is not supported. Use island `urlPrefix` instead of native
paginator `scope`.

Keep filtering, association access, and stable domain ordering in named Table
finders. Queries, entities, counts, and paginated results stay request-local.
Pagination never persists application data.

## Sorting

Declare `sort` and `direction` together and configure an explicit allowlist:

```php
#[State(writable: true, url: true, history: true)]
public string $sort = '';

#[State(writable: true, url: true, history: true)]
public string $direction = 'asc';

/**
 * @var array<string, mixed> Allowed public sorting fields and native defaults.
 */
protected array $paginate = [
    'limit' => 20,
    'order' => ['Resources.id' => 'asc'],
    'sortableFields' => ['title' => 'Resources.title'],
];
```

```php
<th scope="col"><?= $this->Paginator->sort('title', 'Title') ?></th>
```

Sort links toggle direction and select page one. Sorting is disabled without a
`sortableFields` allowlist. Native sort aliases and `SortField` configurations are
supported. Invalid sort keys cannot select arbitrary database columns.

## Filtering and page bounds

Reset the page explicitly when application filters change:

```php
public function updated(array $paths): void
{
    if (in_array('search', $paths, true) && !in_array('page', $paths, true)) {
        $this->page = 1;
    }
}
```

Respect an explicitly supplied page so history can restore filters and paging
together. Pagination bounds negative or stale page numbers and keeps empty results
on page one. The corrected values reach the template, signed snapshot, and URL.
An out-of-bounds read is rerun once at the calculated last page; actions are never
replayed. Renderless actions skip pagination queries until the next rendered update.

## Native templates and links

Interact installs a subclass of CakePHP's `PaginatorHelper` under the usual
`Paginator` alias in island views. `setTemplates()`, template files, and per-call
`templates` options remain available:

```php
$this->Paginator->setTemplates([
    'nextActive' => '<li><a class="next-page" href="{{url}}">{{text}}</a></li>',
]);
```

Keep ordinary anchors using `{{url}}`; no Interact directive is required. The runtime
records generated links separately from their HTML. Custom helpers can extend
`Interact\View\Helper\PaginatorHelper` through the application's `TemplateView` configuration.
Helper results, options, and template changes are isolated between island renders.

Only ordinary clicks on generated links for the current page are enhanced. Modified
clicks, new tabs, downloads, explicit `x-interact:navigate` links, and explicit
alternate destinations retain their normal behavior. Runtime-generated metadata
is internal; applications should not author it.

## Independent listings

Mount separate listing islands with explicit prefixes:

```php
<?= $this->Interact->render('Resources', key: 'articles', urlPrefix: 'articles') ?>
<?= $this->Interact->render('Resources', key: 'products', urlPrefix: 'products') ?>
```

Their page queries become `articles_page` and `products_page`. Updates preserve
unrelated query parameters, the other listing, and unsaved drafts. Generated links
retain the original mounting route, including route arguments and application base
paths, rather than pointing at the update endpoint.
