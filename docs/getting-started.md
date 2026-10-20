---
title: Your first island
description: Build a working counter with a PHP action, a native CakePHP element, and a mount call in an existing page.
---

<span id="build-an-interactive-region"></span>

# Your first island

Build a counter that updates without reloading its surrounding page. You will
add a PHP class, an element, and one mount call to an existing controller view.

Complete [installation](/installation) first: load the plugin and helper, build
and serve the browser runtime, output the layout's script block, and keep CSRF
middleware enabled. No database or custom JavaScript is needed for this example.

## Declare an island

Create `src/Island/Counter.php`. CakePHP's Attribute Resolver discovers island
classes in `src/Island/`; `counter` is the alias used to render this class.

```php [src/Island/Counter.php]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Keeps a count for one region and changes it through a server action.
 */
#[AsIsland('counter', element: 'Interact/counter')]
class Counter extends Island
{
    #[State]
    public int $count = 0;

    /**
     * Increase this island's count when its button is pressed.
     */
    #[Action]
    public function increment(): void
    {
        $this->count++;
    }
}
```

`State` keeps `$count` between requests. Without `writable: true`, the browser
cannot assign it directly. `Action` exposes `increment()` as an intentional way
to change that value.

## Write the element

The declared element receives `$count` on the initial render and every update:

```php [templates/element/Interact/counter.php]
<?php
declare(strict_types=1);

/** @var int $count */
?>
<p aria-live="polite">Count: <?= (string)$count ?></p>
<button type="button" @click="$server.call('increment')"
    :disabled="$server.busy">
    Add one
</button>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

`@click` invokes the PHP action through `$server.call()`. The Alpine shorthand
`:disabled="$server.busy"` disables the button while a request is running.
The alert displays a request failure when one occurs.

## Mount it in an existing page

In a template already rendered by your application, add:

```php [templates/Articles/index.php]
<h1>Articles</h1>
<?= $this->Interact->render('counter') ?>
```

Use the `counter` alias declared by `AsIsland`. The class name and element path
do not determine that alias.

Open your existing articles page. You should see **Count: 0**. Press **Add one**:
the count becomes **1**, and the heading and surrounding layout stay in place.
Each click sends a POST to the update endpoint. If the count does not change,
follow [the troubleshooting checks](/troubleshooting).

Your controller needs no AJAX branch. It still renders its normal action template
on the first request; later requests go directly to Interact's update endpoint.

## Add an independent counter

Render the same alias again with distinct stable keys:

```php
<?= $this->Interact->render('counter', key: 'first') ?>
<?= $this->Interact->render('counter', key: 'second') ?>
```

Each starts at zero and updates independently. Reloading the page starts fresh:
the count is interaction state, and this example does not save it to a database.

## Choose your next step

| Task                                                   | Guide                                     |
| ------------------------------------------------------ | ----------------------------------------- |
| Bind an input or pass an initial value                 | [State and input binding](/guide/state)   |
| Call an action with arguments or show loading feedback | [Actions and feedback](/guide/actions)    |
| Filter a larger model-backed listing                   | [Search and pagination](/guide/search)    |
| Validate and save an editable record                   | [Forms and validation](/guide/forms)      |
| Reuse a controller action's template                   | [Whole-page islands](/guide/page-islands) |

## Application-owned Alpine

If your app already starts Alpine, disable the helper's standalone script and
register Interact with that instance. Follow the
[application-owned Alpine setup](/installation#application-owned-alpine).

## Reuse an action template

An island can own the content of an action template while its normal layout
stays in place. Follow [whole-page islands](/guide/page-islands) for the
cooperating island, controller, and template.
