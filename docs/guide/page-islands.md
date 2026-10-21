---
title: Page islands
description: Reuse an existing controller action template as interactive page content while keeping its normal layout.
---

<span id="whole-page-islands"></span>

# Page islands

A page island renders an action template as interactive content while retaining
its normal layout. It can reuse the existing template.

This example builds on the [counter class](/getting-started). The three files
below work together: the island selects the template, the controller selects the
island view, and the template renders the counter content.

## Select a native template

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\AsIsland;

/**
 * src/Island/CounterPage.php
 *
 * Reuses the counter behavior as the content of a complete action template.
 */
#[AsIsland('counter-page', template: 'Counters/index')]
class CounterPage extends Counter
{
}
```

```php [Controller]
<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Routing\Attribute\Get;
use Interact\View\IslandView;

/**
 * src/Controller/CountersController.php
 *
 * Selects the page island for the initial request and retains the normal layout.
 */
class CountersController extends AppController
{
    /**
     * Render the counter through its declared page template.
     */
    #[Get('/counter', name: 'counter:index')]
    public function index(): void
    {
        $this->viewBuilder()
            ->setClassName(IslandView::class)
            ->setOption('interact', ['alias' => 'counter-page', 'props' => []]);
    }
}
```

```php [Template]
<?php
declare(strict_types=1);

/**
 * templates/Counters/index.php
 *
 * @var int $count
 */
?>
<h1>Counter</h1>
<p aria-live="polite">Count: <?= (string)$count ?></p>
<button type="button" @click="$server.call('increment')" :disabled="$server.busy">
    Add one
</button>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

:::

Open `/counter`. The layout needs to output its `script` block as described in
[installation](/installation).

::: warning Avoid a recursive mount
The page template is already the island's content. Do not put
`$this->Interact->render('counter-page')` inside `templates/Counters/index.php`.
For an embedded island, use an element or another template that does not mount itself.
:::

## Understand which requests run the controller

The initial GET runs `CountersController::index()`, mounts `counter-page`, and
renders the surrounding layout. Pressing the button sends a request to the
update endpoint, invokes `increment()`, and replaces only the island's HTML.
The controller action and layout do not run again.

Supply all island variables through props, state, and `viewData()`. A variable
set only with `$this->set()` in the controller will not be available inside the
island. Pass needed initial values through the ViewBuilder's `interact.props`.

## Choose embedded or whole-page rendering

| Use an embedded island when…                        | Use a whole-page island when…                |
| --------------------------------------------------- | -------------------------------------------- |
| One region needs interaction                        | Most action content updates together         |
| Several regions need independent drafts             | One island owns the page's interactive state |
| You want the normal AppView for surrounding content | You can select `IslandView` for this action  |

`TemplateView` is the internal native renderer for both approaches. `IslandView`
adds the initial page mount and layout workflow. You only need to select
`IslandView` for the whole-page approach. See [rendering and services](/reference/rendering)
for application-specific helpers.

## Routing and navigation

Whole-page rendering does not add client-side routing. Links navigate through
CakePHP normally; the update route handles island interactions. Enhanced
navigation, history integration, and URL-bound state are planned for phase 2.
