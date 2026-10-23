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
features:
  - title: PHP actions, native templates
    details: Declare state and actions in PHP. Render updates with familiar CakePHP templates and elements.
    link: /guide/islands
    linkText: Build an island
  - title: Reactive forms
    details: Bind inputs to writable drafts and display CakePHP validation errors. Your application decides when to save.
    link: /guide/forms
    linkText: Work with forms
  - title: Independent islands
    details: Compose reusable islands that update independently while preserving nearby drafts and Alpine state.
    link: /guide/nesting
    linkText: Compose islands
  - title: Search and URL state
    details: Keep filters and pagination in the URL, with browser Back and Forward support.
    link: /guide/url-state
    linkText: Bind URL state
  - title: Streaming and polling
    details: Stream text, rendered elements, and progress events, or refresh an island on a timer.
    link: /guide/streaming
    linkText: Stream updates
  - title: CakePHP security
    details: Keep your application's CSRF protection, authorization, and middleware. Signed snapshots protect state integrity.
    link: /security
    linkText: Review security
---

<span id="a-php-class-an-element-one-mount-call"></span>

## A PHP class, a template, one mount call

After [installation](/installation), declare a counter, add its template, and
mount it in an existing view. Clicking the button updates only the island.

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

Development preview for **PHP 8.5 and CakePHP 6 development builds**. Install
`dev-main` using the [installation guide](/installation).
