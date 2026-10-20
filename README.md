# CakePHP Interact

Reactive PHP islands for CakePHP. Render native templates, keep application behavior in PHP, and update focused regions through AJAX.

**Experimental, unreleased API. Requires PHP 8.5, CakePHP 6 development builds, and modern browsers.**

```php
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Owns the listing's search draft and renders it through a native CakePHP element.
 */
#[AsIsland('article-list', element: 'Interact/article_list')]
class ArticleList extends Island
{
    #[State(writable: true)]
    public string $search = '';
}
```

```php
<?= $this->Interact->render('article-list') ?>
```

```html
<input
  data-interact-model="search"
  data-interact-update="input"
  data-interact-debounce="250"
/>
```

The initial HTML is server-rendered. Later requests reconstruct the island, authorize the current request, and morph only its region. Alpine handles local interactions. Ordinary CakePHP routing and navigation remain unchanged.

Read the [documentation](https://josbeir.github.io/cakephp-interact/), or browse [getting started](docs/getting-started.md), [API and lifecycle](docs/api.md), [security](docs/security.md), and [contributing](docs/contributing.md) in this repository.

## Run the demo

```sh
composer install
npm ci
npm run build
npm run preview
```

Open `http://127.0.0.1:8765`. The test-only application demonstrates search, pagination, authorized nested editors, dependent selects, and an existing action template used as a whole-page island.

The demo uses seeded SQLite data and a fixture identity. It is not a production authentication example.

## Quality checks

```sh
composer validate --strict
composer check
composer test-coverage
npm run check
npm run test:coverage
npm run test:browser
npm run docs:build
```

PHP and TypeScript line coverage each require at least 95%, including unexecuted source. See [AGENTS.md](AGENTS.md) for contributor contracts.

MIT license.
