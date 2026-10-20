---
title: State and input binding
description: Pass initial props, bind editable fields, and compute template data without transporting query results.
---

# State and input binding

Build a greeting that changes as you type. This example uses the helper and
runtime configured in [installation](/installation).

## Bind a text input

The class and element below work together. Mount them with
`$this->Interact->render('greeting')` in an existing action template.

::: code-group

```php [src/Island/Greeting.php]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\AsIsland;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Owns an editable name and a mount-time greeting for one page region.
 */
#[AsIsland('greeting', element: 'Interact/greeting')]
class Greeting extends Island
{
    #[Prop]
    public string $salutation = 'Hello';

    #[State(writable: true)]
    public string $name = '';
}
```

```php [templates/element/Interact/greeting.php]
<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $salutation
 * @var string $name
 */
?>
<?= $this->Form->create(null, ['url' => false, 'idPrefix' => 'greeting', 'x-interact:submit' => '$server.sync()']) ?>
    <?= $this->Form->control('name', [
        'type' => 'text',
        'label' => 'Your name',
        'value' => $name,
        'x-interact:model.live' => 'name',
    ]) ?>
<?= $this->Form->end() ?>
<p aria-live="polite"><?= (string)h($salutation) ?> <?= (string)h($name) ?></p>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

:::

Type a name: after a short pause, the server receives the new value and renders
the greeting. FormHelper escapes control values; escape other application text
when putting it back into HTML.

## Pass a controller value explicitly

If your controller sets `$welcome = 'Welcome'`, pass it at the mount site:

```php [Your action template]
<?= $this->Interact->render('greeting', props: ['salutation' => $welcome]) ?>
```

The prop initializes the island and remains available on updates. Other
controller variables are not inherited. A prop is browser-visible and cannot
be reassigned through input binding; it is not a place for secrets.

To seed **editable** state from a prop, declare a separate writable property and
copy the prop into it in `mount()`. That hook runs only on the first render,
so later updates do not overwrite the user's draft.

## Choose when to send edits

A plain `x-interact:model="name"` keeps edits local. Add a modifier to choose when
they synchronize:

| Modifier  | Network behavior                             |
| --------- | -------------------------------------------- |
| None      | Send with the next action or explicit sync   |
| `.live`   | Send after a typing pause, 250 ms by default |
| `.blur`   | Send when the control loses focus            |
| `.change` | Send on its native change event              |

For a different typing delay:

```html
<input x-interact:model.live.debounce.400ms="name" />
```

Use `.change` for selects and `.live` for search. Choose one synchronization mode;
debounce overrides only apply to `.live` and accept 0–60,000 whole milliseconds.
Model values are literal writable paths, not Alpine expressions. An action
flushes the latest control values, including a field that has not blurred yet.
Updating a draft never saves it automatically.

## Bind other native controls

Use the same model directive on textareas, selects, radio groups, checkboxes,
and multiple selects. Match the PHP property to the control: `bool` for a single
checkbox, an array for a checkbox group or multiple select, and `int`/`float`
for numeric state. Nullable numeric state can represent an empty selection.

Dotted paths address nested arrays, for example:

```php [Inside a FormHelper form]
<?= $this->Form->control('form.address.city', [
    'type' => 'text',
    'label' => 'City',
    'value' => $form['address']['city'],
    'x-interact:model' => 'form.address.city',
]) ?>
```

Declare the owning `form` property as writable array state and initialize its
shape. Use distinct stable control IDs when rendering repeated fields.
File inputs are outside the current binding implementation.

## Compute data for a render

Add this method to the greeting class to provide an extra template variable:

```php
/**
 * Describe the current draft without adding derived data to the snapshot.
 *
 * @return array{characters: int}
 */
public function viewData(): array
{
    return ['characters' => mb_strlen($this->name)];
}
```

The element can now use `$characters`. For model-backed listings, return query
results here too. Only props and state travel in the snapshot; query results,
services, and entities stay on the server.

`viewData()` cannot reuse a prop or state name, or the reserved `errors` name.
See [the PHP reference](/api#declarations) for supported transported values.
