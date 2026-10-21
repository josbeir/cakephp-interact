---
title: Rendering and helpers
description: Understand TemplateView and IslandView, configure application helpers, and replace container services at explicit boundaries.
---

<span id="rendering-and-services"></span>

# Rendering and helpers

`TemplateView` renders island templates and elements. `IslandView` adds the
initial mount and layout for page islands. Applications can extend their helper
configuration and replace rendering services.

## TemplateView

`Interact\View\TemplateView` renders the declared native template or element.
It supplies exactly the island's props, state, errors, and `viewData()`, restoring
the previous view scope afterwards. Both initial mounts and updates use this
same variable contract.

Its default FormHelper keeps native HTML validation constraints and disables
`autoSetCustomValidity`, which would generate inline JavaScript handlers blocked
by a strict CSP. An application view subclass can explicitly configure the helper
in `initialize()` after calling the parent.

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

```php [View]
<?php
declare(strict_types=1);

namespace App\View;

use Interact\View\TemplateView;

/**
 * src/View/IslandTemplateView.php
 *
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

```php [Factory]
<?php
declare(strict_types=1);

namespace App\Interact;

use App\View\IslandTemplateView;
use Cake\View\View;
use Interact\Runtime\Factory;
use Interact\View\TemplateView;

/**
 * src/Interact/ApplicationFactory.php
 *
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
use Interact\Upload\UploadManager;
```

```php [Inside Application::services()]
$container->addShared(Factory::class, ApplicationFactory::class)
    ->addArguments([Registry::class, SnapshotCodec::class, UploadManager::class]);
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

## Loading placeholders

Use the mount call’s `loading` and `placeholder` options for [lazy loading](/guide/lazy-loading). Custom placeholders use the configured `TemplateView` with declared props only. A custom `RendererInterface` can also implement `PlaceholderRendererInterface::placeholder(Options $options, array $props): string`; otherwise the built-in placeholder is used.
