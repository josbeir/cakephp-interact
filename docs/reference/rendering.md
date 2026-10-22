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

`Interact\View\TemplateView` renders the compiled native template or element.
Metadata compilation infers omitted targets from the island class and retains its owning
plugin. See [template conventions and plugin overrides](/guide/conventions).
It supplies exactly the island's props, state, errors, and `viewData()`, restoring
the previous view scope and plugin context afterwards, including when rendering
throws. Both initial mounts and updates use this same variable contract.

Explicit unqualified targets are relative to the island's owning application or
plugin. Qualified references select another loaded plugin. CakePHP owns template
lookup and application/theme overrides; the hosting route's plugin and prefix do
not select the island's view.

`Definition::declaration` contains the compiled island name, view target, and root
tag. `Definition::plugin` identifies the class's owner. `TemplateView::island()` requires a resolved target; use the
compiled declaration when calling it directly. Manual `Registry::register()`
calls identify plugin ownership from loaded plugins' source directories.

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

<span id="add-application-helpers"></span>

## Configure a custom island view

`Factory::createView()` selects the view used to render island content. Creating
`App\View\IslandTemplateView` alone does not select it: Interact does not discover
application island views by class name.

All three steps below are required:

1. Create an application `TemplateView` subclass and configure its helpers in `initialize()`.
2. Create an application `Factory` subclass whose `createView()` returns that view.
3. Register the application factory under `Interact\Runtime\Factory::class` in `Application::services()`.

The example assumes an application helper named `App\View\Helper\PresentationHelper`
already exists. Replace it with the helpers your application needs.

### 1. Create the view

Create `src/View/IslandTemplateView.php` and configure its helpers after the parent initialization:

```php
<?php
declare(strict_types=1);

namespace App\View;

use App\View\Helper\PresentationHelper;
use Interact\View\TemplateView;

/**
 * src/View/IslandTemplateView.php
 *
 * Supplies application helper configuration to every isolated island render.
 */
class IslandTemplateView extends TemplateView
{
    /**
     * Make the application's presentation helper available to island templates.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->addHelper('Presentation', ['className' => PresentationHelper::class]);
    }
}
```

After the factory is registered below, island templates can use `$this->Presentation`.

### 2. Select the view in a factory

Create `src/Interact/ApplicationFactory.php`. Its `createView()` method explicitly
constructs the application view instead of the plugin's default `TemplateView`:

```php
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

### 3. Register the factory

Add these imports to `src/Application.php`, then add the registration to your
existing `Application::services()` method. This registration is what makes Interact
use `ApplicationFactory` instead of its shipped factory:

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

When resolving `Interact\Runtime\Factory`, CakePHP's container now constructs
`ApplicationFactory`. Its `createView()` constructs `IslandTemplateView`, which
loads the application's helper configuration. Interact checks for an existing
factory definition before registering its default and preserves this application binding.

Keep this registration in application services so both initial renders and update
requests select the same view. Configuring helpers only on the hosting `AppView`
does not configure the isolated island view.

## Dependency boundaries

| Contract                      | Responsibility                                                       |
| ----------------------------- | -------------------------------------------------------------------- |
| `Discovery\Registry`          | Resolve class names, compile declarations, construct fresh instances |
| `Security\SnapshotCodec`      | Sign and verify session/scope-bound state with expiry                |
| `Runtime\Factory`             | Assemble the request runtime and choose its native view              |
| `Rendering\RendererInterface` | Render a declaration with explicit data into HTML                    |

Island constructors may receive application services through CakePHP's container.
Register those dependencies normally and never transport them as state. In the
demo, Table access uses `LocatorAwareTrait::fetchTable()` and named finders;
non-Table services can remain constructor dependencies.

Custom renderers belong at `RendererInterface`, usually assembled by a custom
factory. Keep signing, authorization, and state validation in the normal runtime
flow rather than bypassing them in an adapter.

## Loading placeholders

Use the mount call’s `loading` and `placeholder` options for [lazy loading](/guide/lazy-loading). Custom placeholders use the configured `TemplateView` with declared props only. A custom `RendererInterface` can also implement `PlaceholderRendererInterface::placeholder(Options $options, array $props): string`; otherwise the built-in placeholder is used.
