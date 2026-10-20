---
title: Installation
description: Install the development plugin from Packagist in a CakePHP application, load its helper, and build the browser runtime.
---

# Installation

You need **PHP 8.5**, a **CakePHP 6 development application**, Composer, and
**Node 24.11+ or Node 26+** to build the browser assets. Interact is available on
[Packagist](https://packagist.org/packages/josbeir/cakephp-interact) as `dev-main`
only. There is no stable release yet, and the API is experimental.

To explore without integrating an existing application, [run the demo](/examples).

## Install in your application

Run these commands from your CakePHP application's root directory:

```sh
composer config minimum-stability dev
composer config prefer-stable true
composer require josbeir/cakephp-interact:dev-main --with-all-dependencies
```

Packagist is Composer's default repository; no custom repository or separate
clone is needed. The stability settings permit CakePHP's development dependencies
while preferring stable versions where possible. Interact targets CakePHP 6;
these steps do not make an existing CakePHP 5 application compatible.

## Build the browser runtime

The current Packagist development distribution uses a GitHub source archive,
which does not include generated browser assets. Build them in the installed
package before serving your application:

```sh
cd vendor/josbeir/cakephp-interact
npm ci
npm run build
cd ../../..
```

The build creates `webroot/js/runtime.js`, the standalone browser runtime, and
`build/js/`, the npm module. Include the generated assets in your deployment and
repeat the build after Composer installs or updates the package. Node is needed
at build time only.

## Load the plugin

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

Use Alpine 3.17.4 or later in the 3.x series. Interact uses Alpine’s native
expression evaluation API to keep submission promises and failures observable.

Use this integration when your application already starts Alpine. After building
the browser runtime above, install the built module from your application's root
directory:

```sh
npm install ./vendor/josbeir/cakephp-interact @alpinejs/csp
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

## Prepare the plugin checkout

For plugin development, you can use a local checkout instead of the Packagist
package. Clone the plugin next to your application, then build its assets:

```sh
git clone https://github.com/josbeir/cakephp-interact.git
cd cakephp-interact
npm ci
npm run build
```

From your application's root directory, configure the sibling checkout as a path
repository before running the Composer installation commands above:

```sh
composer config repositories.interact path ../cakephp-interact
```

Build assets in that checkout. For application-owned Alpine, use
`npm install ../cakephp-interact @alpinejs/csp` instead of the vendor path.
