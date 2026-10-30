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
`App\View\IslandTemplateView` alone does not select it: configure its class name
explicitly.

Create an application subclass of `TemplateView`, then select it with
[`Interact.view.className`](/configuration#island-view-class). Both initial
content and later updates use that class; no factory subclass is required for
ordinary view customization.

The example assumes an application helper named `App\View\Helper\PresentationHelper`
already exists. Replace it with the helpers your application needs.

```php [src/View/IslandTemplateView.php]
<?php
declare(strict_types=1);

namespace App\View;

use App\View\Helper\PresentationHelper;
use Interact\View\TemplateView;

/**
 * Supplies application helper configuration to every isolated island render.
 */
class IslandTemplateView extends TemplateView
{
    /**
     * Register application presentation helpers after the Interact defaults.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->addHelper('Presentation', ['className' => PresentationHelper::class]);
    }
}
```

```php [config/app.php — configuration excerpt]
'Interact' => [
    'view' => ['className' => \App\View\IslandTemplateView::class],
],
```

Island templates can now use `$this->Presentation`. Keep CakePHP's native view
constructor signature. The configured class must extend `TemplateView`; helper
configuration on an ordinary hosting `AppView` is not copied into island content.
For page islands, keep selecting `IslandView` on the controller; the configured
class controls island content while the page view retains the outer layout.

### Custom factories

Applications needing different runtime assembly can still subclass
`Interact\Runtime\Factory`, override `createView(View $host): TemplateView`,
and register it under `Factory::class` in `Application::services()`. Existing
application factory definitions take precedence over the default registration.
Pass `Registry`, `SnapshotCodec`, `UploadManager`, and `Configuration` as
constructor arguments when using the inherited constructor and application settings.
A custom factory that overrides view selection owns that choice.

## Dependency boundaries

| Contract                      | Responsibility                                                       |
| ----------------------------- | -------------------------------------------------------------------- |
| `Discovery\Registry`          | Resolve class names, compile declarations, construct fresh instances |
| `Security\SnapshotCodec`      | Sign and verify session/scope-bound state with expiry                |
| `Runtime\Factory`             | Assemble the request runtime and choose its native view              |
| `Rendering\RendererInterface` | Render a declaration with explicit data into HTML                    |

Island constructors may receive application services through CakePHP's container.
Register those dependencies normally and never transport them as state. In the
demo, Table access uses the base island's inherited `fetchTable()` and named finders;
non-Table services can remain constructor dependencies.
Use [`initialize()`](/guide/lifecycle#initialize) to assign request-local Table
properties before authorization. The runtime calls it after construction and
before assigning props or restoring state.

Custom registry callers pass the current request to
`Registry::create($definition, $request)`; it is assigned before `initialize()`.

Custom renderers belong at `RendererInterface`, usually assembled by a custom
factory. Keep signing, authorization, and state validation in the normal runtime
flow rather than bypassing them in an adapter.

## Loading placeholders

Use the mount call’s `loading` and `placeholder` options for [lazy loading](/guide/lazy-loading). Custom placeholders use the configured `TemplateView` with declared props only. A custom `RendererInterface` can also implement `PlaceholderRendererInterface::placeholder(Options $options, array $props): string`; otherwise the built-in placeholder is used.
