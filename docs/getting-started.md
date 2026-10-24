---
title: Quickstart
description: Build a working counter with a PHP action, a native CakePHP template, and a mount call in an existing page.
---

<span id="build-an-interactive-region"></span>

<span id="your-first-island"></span>

# Quickstart

Build a counter that updates without reloading its surrounding page. You will
add a PHP class, a template, and one mount call to an existing controller view.

Complete [installation](/installation) first: load the plugin and helper, serve
the included browser runtime, output the layout's script block, and keep CSRF
middleware enabled. The runtime includes Alpine. No database or separate
JavaScript file is needed for this example.

Alpine handles browser events and presentation. Interact supplies `$server` to
connect those expressions to PHP. After this counter, the
[Alpine and Interact](/guide/alpine) primer adds local state and transitions.

## Declare an island

Create `src/Island/CounterIsland.php`. Interact resolves `Counter` to this class
when the helper renders it. No registration or discovery attribute is required.

```php [src/Island/CounterIsland.php]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Keeps a count for one region and changes it through a server action.
 *
 * Save as src/Island/CounterIsland.php.
 */
class CounterIsland extends Island
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

<span id="write-the-element"></span>

## Write the template

The inferred template receives `$count` on the initial render and every update:

```php [templates/island/counter.php]
<?php
declare(strict_types=1);

/**
 * templates/island/counter.php
 *
 * @var int $count
 */
?>
<p aria-live="polite">Count: <?= (string)$count ?></p>
<button
    type="button"
    @click="$server.call('increment')"
    :disabled="$server.busy"
>
    Add one
</button>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

`@click` is Alpine's shorthand for an event listener; `$server.call('increment')`
is the Interact operation that sends a request to PHP. Alpine's `:disabled`
binding reads `$server.busy`, and `x-text` displays a request failure. These
bindings update in the browser without making requests of their own.

## Mount it in an existing page

In a template already rendered by your application, add:

```php [templates/Articles/index.php]
<?php
declare(strict_types=1);

/**
 * templates/Articles/index.php
 *
 * @var \Cake\View\View $this
 */
?>
<h1>Articles</h1>
<?= $this->Interact->render('Counter') ?>
```

`Counter` resolves to `App\Island\CounterIsland`. Its default template is
`templates/island/counter.php`; underscored names such as `article_editor` resolve
to `ArticleEditorIsland`.

Open your existing articles page. You should see **Count: 0**. Press **Add one**:
the count becomes **1**, and the heading and surrounding layout stay in place.
Each click sends a POST to the update endpoint. If the count does not change,
follow [the troubleshooting checks](/troubleshooting).

Your controller needs no AJAX branch. It still renders its normal action template
on the first request; later requests go directly to Interact's update endpoint.

## Add an independent counter

Render the same island name again with distinct stable keys:

```php
<?= $this->Interact->render('Counter', key: 'first') ?>
<?= $this->Interact->render('Counter', key: 'second') ?>
```

Each starts at zero and updates independently. Reloading the page starts fresh:
the count is interaction state, and this example does not save it to a database.

See [Conventions](/guide/conventions) for organizing related islands into matching
class and template folders.

## Choose your next step

| Task                                                   | Guide                                 |
| ------------------------------------------------------ | ------------------------------------- |
| Look up a directive                                    | [HTML directives](/directives/)       |
| Look up a JavaScript bridge member                     | [$server](/reference/server)          |
| Bind an input or pass an initial value                 | [Properties](/guide/state)            |
| Read PHP data from Alpine or a JavaScript file         | [Alpine and `$server`](/guide/alpine) |
| Call an action with arguments or show loading feedback | [Actions](/guide/actions)             |
| Upload and validate files                              | [File uploads](/guide/uploads)        |
| Filter a larger model-backed listing                   | [Search and pagination](/demo#search) |
| Validate and save an editable record                   | [Article form](/demo#forms)           |
| Reuse a controller action's template                   | [Page islands](/guide/page-islands)   |

## Application-owned Alpine

If your app already starts Alpine, disable the helper's standalone script and
register Interact with that instance. Follow the
[application-owned Alpine setup](/installation#application-owned-alpine).

## Reuse an action template

An island can own the content of an action template while its normal layout
stays in place. Follow [whole-page islands](/guide/page-islands) for the
cooperating island, controller, and template.
