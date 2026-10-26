---
title: 'Pagination'
description: Declare typed paging state and native CakePHP settings for independent island listings.
---

# Pagination

`#[Pagination]` declares one typed paging selection and its native CakePHP paginator
settings. `Island::paginate()` applies that selection to an application-owned query.
Ordinary `PaginatorHelper` links update the owning island, including links built
with custom helper templates. URL bindings are optional.

## Minimal pagination

Declare a `PaginationState` property. Interact initializes a fresh object before
`initialize()` on every request; no constructor assignment or trait is required.

```php
<?php
declare(strict_types=1);

namespace App\Island;

use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Pagination;
use Interact\Island;
use Interact\Pagination\PaginationState;

/**
 * Renders a page from the application's Resources Table.
 */
class ResourcesIsland extends Island
{
    use LocatorAwareTrait;

    #[Pagination(
        settings: [
            'limit' => 20,
            'order' => ['Resources.id' => 'asc'],
        ],
    )]
    public PaginationState $paging;

    /**
     * Fetch request-local results without transporting database rows.
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $query = $this->fetchTable('Resources')->find();

        return [
            'resources' => $this->paginate($query),
        ];
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
 * @var \Interact\Pagination\PaginationState $paging
 * @var \Cake\Datasource\Paging\PaginatedInterface<int, \Cake\Datasource\EntityInterface> $resources
 */
?>
<ul>
    <?php foreach ($resources as $resource): ?>
        <li><?= h((string)$resource->get('title')) ?></li>
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
application `Resources` Table with a `title` column. The [search example](/demo#search)
includes a tested Table, SQLite schema, template, and mount call.

## Configuration and state

`paginate()` returns a native `PaginatedInterface` and accepts:

- `$object`: a `RepositoryInterface` or `QueryInterface`.
- `$settings`: optional native paginator overrides; defaults to `[]`.
- `$paging`: the selected `PaginationState`; optional with one declaration.

Resolve queries and Tables through the application's normal locator.
Per-call settings override attribute settings using CakePHP's shallow array precedence.
Use per-call settings for request-dependent configuration, callbacks, or factory
calls such as `SortField::desc()` that cannot be evaluated in an attribute.

| Attribute option | Default | Behavior                                                                        |
| ---------------- | ------- | ------------------------------------------------------------------------------- |
| `settings`       | `[]`    | Server-owned native numeric paginator settings.                                 |
| `url`            | `false` | Bind every enabled field with `true`, or selected fields using an alias map.    |
| `history`        | `false` | Push history for every bound field with `true`, or a list of bound field names. |
| `editableLimit`  | `false` | Accept browser changes to the page size.                                        |
| `defaults`       | `[]`    | Override the initial `page`, `sort`, or `direction` selection.                  |

Each property owns its settings and selection. Native `limit`, `maxLimit`, `order`,
`finder`, per-repository settings, and `sortableFields` are supported. `className`
may select `Numeric` or a compatible numeric paginator subclass. `SimplePaginator`
is unsupported. Use explicit query aliases and the mount's `urlPrefix` instead of
native paginator `scope`.

`PaginationState` has native typed PHP properties:

| Member              | Default                     | Behavior                                                      |
| ------------------- | --------------------------- | ------------------------------------------------------------- |
| `page: int`         | `1`                         | Current page, normalized against available results.           |
| `sort: string`      | `''`                        | Public sort key; an empty key uses configured ordering.       |
| `direction: string` | `'asc'`                     | Accepted sort direction.                                      |
| `limit: int`        | `settings['limit']` or `20` | Accepted page size; writable only with `editableLimit: true`. |

Set a different initial selection with `defaults: ['sort' => 'title']`. For editable
limits, the immutable initial page size comes from top-level `settings['limit']`;
that writable selection takes precedence over per-call page-size defaults. Without
`editableLimit`, the effective native settings choose the page size on each render.

Templates receive the typed `$paging` object. PHP uses `$this->paging->page`;
browser expressions use `$server.paging.page`, and model bindings use `paging.page`.
Snapshots contain only the declared scalar selection under `paging`. The object,
settings, queries, entities, counts, and results stay request-local. Ordinary State
properties still reject arbitrary PHP objects. A paging property cannot also declare
`State`, `Prop`, or `Upload`.

Keep domain filtering, association access, and stable ordering in named Table
finders. Pagination never persists application data.

## Optional URL bindings

Without `url`, paging stays in signed state. Reloading starts from application defaults.
Opt into conventional flat query keys and Back/Forward history:

```php
#[Pagination(
    settings: ['limit' => 20],
    url: true,
    history: true,
)]
public PaginationState $paging;
```

For example, `$this->paging->page` maps to `?page=2`. Customize keys independently
of the PHP property name:

```php
#[Pagination(
    settings: [
        'limit' => 20,
        'maxLimit' => 50,
    ],
    url: [
        'page' => 'p',
        'sort' => 'order',
        'direction' => 'dir',
        'limit' => 'pageSize',
    ],
    history: ['page', 'limit'],
    editableLimit: true,
)]
public PaginationState $paging;
```

An alias map binds only its listed fields. History can reference only URL-bound
fields, and aliases must be unique across all URL declarations in the island.
The mount's `urlPrefix` applies to each alias: `urlPrefix: 'articles'` makes `p`
become `articles_p`. See [URL state](/guide/url-state) for history behavior.

When any enabled paging field lacks a URL binding, generated links use opaque
fragment targets and require JavaScript to apply the complete selection. Those
fragments never encode non-URL values. Opening such a link in a new tab uses the
application defaults for unbound fields. Bind every enabled paging field when
links must restore the complete listing through a normal GET without JavaScript.

## Sorting

Configure an explicit public allowlist in the attribute:

```php
#[Pagination(
    settings: [
        'limit' => 20,
        'order' => ['Resources.id' => 'asc'],
        'sortableFields' => ['title' => 'Resources.title'],
    ],
)]
public PaginationState $paging;
```

```php
<th scope="col"><?= $this->Paginator->sort('title', 'Title') ?></th>
```

Sort links toggle direction and select page one. Sorting is disabled without a
`sortableFields` allowlist. Native sort aliases and `SortField` configurations are
supported. Invalid sort keys cannot select arbitrary database columns.

## Editable page sizes

Enable `editableLimit: true`, then bind a control to the typed scalar path:

::: code-group

```html [HTML]
<select aria-label="Per page" x-interact:model.change="paging.limit">
  <option value="10">10</option>
  <option value="20" selected>20</option>
</select>
```

```php [FormHelper]
<?= $this->Form->control('pageSize', [
    'label' => 'Per page',
    'options' => [10 => '10', 20 => '20'],
    'value' => $paging->limit,
    'x-interact:model.change' => 'paging.limit',
]) ?>
```

:::

Numeric control values are converted using the declared integer type. `maxLimit`
bounds accepted sizes. Applications validate any narrower list of choices.

## Filtering and page bounds

Reset only the relevant page when application filters change:

```php
use Interact\UpdatedPaths;

public function updated(UpdatedPaths $paths): void
{
    if ($paths->has('search') && !$paths->has('paging.page')) {
        $this->paging->page = 1;
    }
}
```

Respect explicitly supplied page paths so history can restore filters and paging
together. Pagination bounds negative or stale page numbers and keeps empty results
on page one. Corrected values reach the PHP object, template, signed snapshot,
and URL bindings. An out-of-bounds read is rerun once at the calculated last page;
actions are never replayed. Renderless actions skip queries and normalization until
the next rendered update.

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
`Interact\View\Helper\PaginatorHelper` through the application's `TemplateView`
configuration. Helper results, options, and template changes are isolated between
island renders.

Only ordinary clicks on generated links for the current page are enhanced. Modified
clicks, new tabs, downloads, explicit `x-interact:navigate` links, and explicit alternate
destinations retain native behavior. Generated metadata is internal.

Native destination overrides (`url` or `routePlaceholders` helper options, or the
URL argument to `generateUrl()`) require every enabled paging field in the selected
listing to be URL-bound. Interact rejects these overrides for non-URL state.

## Independent listings

Declare one property per independent listing, including independent queries against
the same Table:

```php
#[Pagination(
    settings: ['limit' => 20],
    url: [
        'page' => 'articlePage',
        'sort' => 'articleSort',
        'direction' => 'articleDirection',
    ],
    history: true,
)]
public PaginationState $articlesPaging;

#[Pagination(
    settings: ['limit' => 10],
    url: [
        'page' => 'commentPage',
        'sort' => 'commentSort',
        'direction' => 'commentDirection',
    ],
    history: true,
)]
public PaginationState $commentsPaging;
```

Select the matching state when paginating:

```php
return [
    'articles' => $this->paginate(
        $articlesQuery,
        paging: $this->articlesPaging,
    ),
    'comments' => $this->paginate(
        $commentsQuery,
        paging: $this->commentsPaging,
    ),
];
```

The queries must come from application-owned finders after resource authorization.
A single declaration is selected automatically. Multiple declarations require an
explicit `paging` argument; each can paginate one query per request. Settings belong
to the selected property rather than the Table name.

With multiple results, select each through the native helper before rendering its
controls:

```php
$this->Paginator->setPaginated($articles);
// Render article controls.
$this->Paginator->setPaginated($comments);
// Render comment controls.
```

Links preserve the other listing's accepted selection, unrelated query parameters,
and unsaved drafts. Back/Forward restores all URL-bound selections without actions.
Generated links retain the original mount route, arguments, and application base
path. See the tested [multiple-paginator demo](/demo#multiple-paginators).

Independent island instances can still use mount prefixes:

```php
<?= $this->Interact->render(
    'Resources',
    key: 'articles',
    urlPrefix: 'articles',
) ?>
<?= $this->Interact->render(
    'Resources',
    key: 'products',
    urlPrefix: 'products',
) ?>
```

Their conventional page keys become `articles_page` and `products_page`.
