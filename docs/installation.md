---
title: Installation
description: Install the development plugin in a CakePHP application, load its helper, and serve the browser runtime.
---

# Installation

You need **PHP 8.5**, a **CakePHP 6 development application**, Composer, and
**Node 24+** to build the browser assets from this checkout. The current plugin
is a development preview; the steps below use a local Composer path repository.

To explore without integrating an existing application, [run the demo](/examples).

## Prepare the plugin checkout

Clone the plugin next to your application. This example uses sibling directories
named `cakephp-interact` and `my-app`:

```sh
git clone git@github.com:josbeir/cakephp-interact.git
cd cakephp-interact
npm ci
npm run build
```

The build creates `webroot/js/runtime.js`, the standalone browser runtime. Keep
this file available when serving your application; a source checkout needs this
build even when PHP installation succeeds.

## Install in your application

Run these commands from `my-app/`:

```sh
composer config repositories.interact path ../cakephp-interact
composer require josbeir/cakephp-interact:dev-main --with-all-dependencies
```

Your application's Composer configuration must permit CakePHP's development
version: `"minimum-stability": "dev"` with `"prefer-stable": true`. Interact targets
CakePHP 6; these steps do not make an existing CakePHP 5 application compatible.

Add the plugin inside your existing `Application::bootstrap()` method, after
`parent::bootstrap()`:

```php
use Interact\InteractPlugin;

$this->addPlugin(InteractPlugin::class);
```

The `use` declaration belongs with the imports at the top of
`src/Application.php`; the call belongs inside the method. Keep your existing
plugin registrations.

## Load the helper

Add this configuration to your existing `AppView`. The complete minimal class is:

```php [src/View/AppView.php]
<?php
declare(strict_types=1);

namespace App\View;

use Cake\View\View;
use Interact\View\Helper\InteractHelper;

/**
 * Makes the island mounting helper available to application templates.
 */
class AppView extends View
{
    /**
     * Register Interact alongside the application's other view helpers.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->addHelper('Interact', ['className' => InteractHelper::class]);
    }
}
```

The helper queues the standalone script once. Ensure your layout outputs the
script block, usually inside `<head>`:

```php [templates/layout/default.php — inside head]
<?= $this->fetch('script') ?>
```

Serve plugin assets through your normal CakePHP asset setup. For a web server
serving the application webroot directly, create the plugin asset symlink:

```sh
bin/cake plugin assets symlink Interact
```

Check that `/interact/js/runtime.js` returns JavaScript under your application's
base URL. See [troubleshooting](/troubleshooting#the-page-renders-but-buttons-do-nothing)
if it returns a 404 or HTML.

## Keep the update route protected

The plugin registers `POST /_interact/update`, named `interact:update`.
Your middleware stack must run CakePHP's error handler, routing, body parser,
and CSRF protection, together with your authentication integration.
The update route needs the same access protections as the original page.

Keep CSRF enabled. The helper reads the request's CSRF token and the runtime sends
it with same-origin update requests. For protected route scopes, read
[security and deployment](/security) before exposing an island.

**Next:** [Build your first island](/getting-started).

## Application-owned Alpine

Use this integration when your application already starts Alpine. Build the npm
module in the plugin checkout with `npm run build`, then install that checkout
from your application's JavaScript project:

```sh
npm install ../cakephp-interact @alpinejs/csp
```

Set `autoScript` to `false` in the helper configuration:

```php
$this->addHelper('Interact', [
    'className' => InteractHelper::class,
    'autoScript' => false,
]);
```

Register the plugin before your application's existing `Alpine.start()`:

```ts [Application JavaScript entry]
import Alpine from '@alpinejs/csp';
import interact from '@josbeir/cakephp-interact';

Alpine.plugin(interact);
Alpine.start();
```

Load this entry using your application's bundler. Use one Alpine instance per
page; omit the standalone Interact script for this integration. If you use
standard `alpinejs` instead, its expression evaluator has different CSP needs.
