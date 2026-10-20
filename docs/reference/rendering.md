---
title: Rendering and services
description: Understand TemplateView and IslandView, configure application helpers, and replace container services at explicit boundaries.
---

# Rendering and services

Ordinary islands need a class, a template or element, and the helper. Use this
reference when an application needs custom helpers or service implementations.

## TemplateView

`Interact\View\TemplateView` renders the declared native template or element.
It supplies exactly the island's props, state, errors, and `viewData()`, restoring
the previous view scope afterwards. Both initial mounts and updates use this
same variable contract.

An embedded island can live inside an ordinary CakePHP `AppView`. The rendering
factory creates a separate `TemplateView` for its content. Configuration on the
hosting `AppView`, including additional helpers, is not automatically copied.

This isolation prevents an island from accidentally relying on controller-only
variables that would disappear on the next update.

## IslandView

`Interact\View\IslandView` extends `TemplateView` and supplies the initial
whole-page workflow. It reads the ViewBuilder's `interact.alias` and
`interact.props`, mounts that island, and renders the normal layout once.

Later updates render only the declared island content. They do not rerun the
page controller or render its layout. Select this view only for
[whole-page islands](/guide/page-islands).

## Add application helpers

For helpers needed inside islands, subclass `TemplateView` and initialize them
there. A custom `Factory` can return that view for both initial and update
renders:

::: code-group

```php [src/View/IslandTemplateView.php]
<?php
declare(strict_types=1);

namespace App\View;

use Interact\View\TemplateView;

/**
 * Supplies application helper configuration to every isolated island render.
 */
class IslandTemplateView extends TemplateView
{
    /**
     * Make the application's normal formatting helpers available to island elements.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->addHelper('Number');
    }
}
```

```php [src/Interact/ApplicationFactory.php]
<?php
declare(strict_types=1);

namespace App\Interact;

use App\View\IslandTemplateView;
use Cake\View\View;
use Interact\Runtime\Factory;
use Interact\View\TemplateView;

/**
 * Applies the application's island view configuration at the rendering boundary.
 */
class ApplicationFactory extends Factory
{
    /**
     * Preserve the host request and response while selecting the application view.
     */
    protected function createView(View $host): TemplateView
    {
        return new IslandTemplateView(
            $host->getRequest(),
            $host->getResponse(),
            viewOptions: ['theme' => $host->getTheme()],
        );
    }
}
```

:::

Register it in your existing `Application::services()` using these imports and
registration. The container will resolve the registry and snapshot services
registered by the plugin:

```php [Application imports]
use App\Interact\ApplicationFactory;
use Interact\Discovery\Registry;
use Interact\Runtime\Factory;
use Interact\Security\SnapshotCodec;
```

```php [Inside Application::services()]
$container->addShared(Factory::class, ApplicationFactory::class)
    ->addArguments([Registry::class, SnapshotCodec::class]);
```

The plugin preserves application-provided definitions. Keep the same custom
factory on initial and update requests.

## Dependency boundaries

| Contract                      | Responsibility                                                    |
| ----------------------------- | ----------------------------------------------------------------- |
| `Discovery\Registry`          | Discover aliases, compile declarations, construct fresh instances |
| `Security\SnapshotCodec`      | Sign and verify session/scope-bound state with expiry             |
| `Runtime\Factory`             | Assemble the request runtime and choose its native view           |
| `Rendering\RendererInterface` | Render a declaration with explicit data into HTML                 |

Island constructors may receive application services through CakePHP's container.
Register those dependencies normally and never transport them as state. In the
demo, Table access uses `LocatorAwareTrait::fetchTable()` and named finders;
non-Table services can remain constructor dependencies.

Custom renderers belong at `RendererInterface`, usually assembled by a custom
factory. Keep signing, authorization, and state validation in the normal runtime
flow rather than bypassing them in an adapter.
