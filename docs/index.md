---
title: CakePHP Interact
titleTemplate: false
description: Build interactive CakePHP pages with Alpine for browser behavior and Interact for PHP state, actions, and server-rendered updates.
layout: home
hero:
  name: CakePHP Interact
  text: Alpine in the browser. CakePHP on the server.
  tagline: Use Alpine for local interactions. Connect PHP state, actions, and native templates with Interact.
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
      text: Explore the demo
      link: /demo
features:
  - title: Built on Alpine
    details: Use Alpine's local state, events, bindings, and transitions directly. Alpine is included in the browser runtime.
    link: /guide/alpine
    linkText: Use Alpine with Interact
  - title: PHP actions, native templates
    details: Declare state and actions in PHP. Render updates with familiar CakePHP templates and elements.
    link: /guide/islands
    linkText: Build an island
  - title: Reactive forms
    details: Bind inputs to writable drafts and display CakePHP validation errors. Your application decides when to save.
    link: /guide/forms
    linkText: Work with forms
  - title: Search and URL state
    details: Keep filters and pagination in the URL, with browser Back and Forward support.
    link: /guide/url-state
    linkText: Bind URL state
  - title: Realtime and streaming
    details: Push updates to subscribed islands across tabs. Stream text, HTML, and progress events, or refresh on a timer.
    link: /guide/realtime
    linkText: Send live updates
  - title: CakePHP security
    details: Keep your application's CSRF protection, authorization, and middleware. Signed snapshots protect state integrity.
    link: /security
    linkText: Review security
---

<span id="a-php-class-an-element-one-mount-call"></span>

## A PHP class, a template, one mount call

After [installation](/installation), declare a counter, add its template, and
mount it in an existing view. Alpine's `@click` calls the Interact `$server`
bridge; PHP increments the count and returns the island's updated HTML.

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Owns the displayed count and its increment action.
 *
 * Save as src/Island/CounterIsland.php.
 */
class CounterIsland extends Island
{
    #[State]
    public int $count = 0;

    /**
     * Increase the displayed count.
     */
    #[Action]
    public function increment(): void
    {
        $this->count++;
    }
}
```

```php [Template]
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
```

```php [Mount]
<?= $this->Interact->render('Counter') ?>
```

:::

Use Alpine's `x-data`, `x-show`, and `x-transition` for browser-only interactions.
These send no request. Start with [Quickstart](/getting-started), then follow
[Alpine and Interact](/guide/alpine) to combine local UI state with PHP actions.

Development preview for **PHP 8.5 and CakePHP 6 development builds**. Install
`dev-main` using the [installation guide](/installation).
