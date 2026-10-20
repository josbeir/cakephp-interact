---
title: CakePHP Interact
titleTemplate: false
description: Add live search, inline editing, and conditional forms to CakePHP with server-rendered PHP islands. Start with a working example.
layout: home
hero:
  name: CakePHP Interact
  text: Interactive pages. Written in PHP.
  tagline: Keep your CakePHP templates and application logic. Update the part of the page that changed.
  image:
    src: /branding/interact-mark.svg
    alt: CakePHP Interact logo
    width: 320
    height: 320
  actions:
    - theme: brand
      text: Build your first island
      link: /getting-started
    - theme: alt
      text: Explore the examples
      link: /examples
---

## A PHP class, an element, one mount call

An island owns a region of your page. A button calls its PHP action, and Interact
updates that region with freshly rendered HTML.

These three files work together after [installation](/installation):

::: code-group

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

```php [templates/element/Interact/counter.php]
<?php
declare(strict_types=1);

/** @var int $count */
?>
<p aria-live="polite">Count: <?= (string)$count ?></p>
<button type="button" data-interact-click="increment"
    :disabled="$server.busy">
    Add one
</button>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

```php [templates/Articles/index.php]
<h1>Articles</h1>
<?= $this->Interact->render('counter') ?>
```

:::

Use the same approach for [live search](/guide/search),
[editable forms](/guide/forms), and [independent nested editors](/guide/nesting).
CakePHP continues to own queries, validation, authorization, and saving.

Interact is a development preview for **PHP 8.5 and CakePHP 6 development builds**.
Start with [installation](/installation), or [run the included demo](/examples)
to try the interactions before integrating it into an application.
