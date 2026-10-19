# Build an interactive region

Interact is an unreleased plugin targeting PHP 8.5 and CakePHP 6 development builds. Install this repository as a Composer path dependency while developing; the public package is not published yet.

Load the plugin in your application bootstrap:

```php
use Interact\InteractPlugin;

$this->addPlugin(InteractPlugin::class);
```

Enable CakePHP's body parser and CSRF middleware before controller dispatch. Use your application's authentication and authorization middleware on the update route too. See [security](./security.md).

## Declare an island

Put discoverable classes under `src/Island/`. Declare the template or element explicitly.

::: code-group

```php [src/Island/Greeting.php]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Owns an editable greeting while keeping its HTML in a native element.
 */
#[AsIsland('greeting', element: 'Interact/greeting')]
class Greeting extends Island
{
    #[State(writable: true)]
    public string $name = '';
}
```

```php [templates/element/Interact/greeting.php]
<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var string $name
 */
?>
<label>
    Name
    <input value="<?= (string)h($name) ?>" data-interact-model="name" data-interact-update="input">
</label>
<p>Hello <?= (string)h($name) ?></p>
```

:::

Load and use the helper in your normal controller/view workflow:

```php
use Interact\View\Helper\InteractHelper;

$this->viewBuilder()->addHelper('Interact', ['className' => InteractHelper::class]);
```

```php
<?= $this->Interact->render('greeting') ?>
```

Your layout must output `<?= $this->fetch('script') ?>`. The helper loads the standalone Alpine CSP/Morph bundle once. The release build supplies `webroot/js/runtime.js`; development checkouts require `npm ci && npm run build`.

## Application-owned Alpine

Disable the helper's `autoScript` option and register Interact before starting Alpine:

```ts
import Alpine from '@alpinejs/csp';
import interact from '@josbeir/cakephp-interact';

Alpine.plugin(interact);
Alpine.start();
```

Do not load a second Alpine instance. The standalone bundle intentionally rejects duplicate initialization.

## Reuse an action template

A whole-page island can declare `template: 'Articles/index'`. Select `Interact\View\IslandView` through the controller's ViewBuilder:

```php
use Interact\View\IslandView;

$this->viewBuilder()
    ->setClassName(IslandView::class)
    ->setOption('interact', ['alias' => 'article-page', 'props' => []]);
```

The initial request renders the surrounding layout. Updates render the island template alone, without rerunning the controller action. Supply required variables through declared props, state, and `viewData()`.

An embedded island must not render the hosting template containing its own helper call: that would recurse.
