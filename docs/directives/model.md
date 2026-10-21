---
title: 'x-interact:model'
description: Bind writable PHP state to text, numbers, selects, radio buttons, checkbox groups, uploads, and Alpine modelable widgets.
---

# x-interact:model

Keep a field's value in the owning island's writable PHP state. The default
binding updates a browser draft immediately and sends it with the next action
or explicit synchronization.

## Preview a name

After [installation](/installation), add these three cooperating files. This
example previews a draft; it does not save a record.

::: code-group

```php [Island]
<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Owns an editable name and renders its synchronized preview.
 *
 * Save as src/Island/ProfileDraft.php.
 */
#[AsIsland('profile-draft', element: 'Interact/profile_draft')]
class ProfileDraft extends Island
{
    #[State(writable: true)]
    public string $name = '';
}
```

```php [Element]
<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * templates/element/Interact/profile_draft.php
 *
 * @var \Interact\View\TemplateView $this
 * @var string $name
 */
?>
<?= $this->Form->create(null, [
    'url' => false,
    'x-interact:submit' => '$server.sync()',
]) ?>
    <?= $this->Form->control('name', [
        'label' => 'Your name',
        'value' => $name,
        'x-interact:model' => 'name',
    ]) ?>
    <?= $this->Form->button('Preview') ?>
<?= $this->Form->end() ?>
<p aria-live="polite">Preview: <?= h($name) ?></p>
```

```php [Mount]
<?= $this->Interact->render('profile-draft') ?>
```

:::

Typing updates `$server.state.name` in the browser. Pressing **Preview** sends
the draft to PHP and rerenders the preview. For a live preview, replace the
binding with `x-interact:model.live`.

The directive's value is a **literal writable path**, such as `name` or
`form.address.city`. Use `name`, rather than `$server.state.name`, in this
attribute. Props and state without `writable: true` cannot receive model edits.

## Choose when to send edits

| Binding                                       | When it sends a request                  |
| --------------------------------------------- | ---------------------------------------- |
| `x-interact:model="name"`                     | With the next action or `$server.sync()` |
| `x-interact:model.live="name"`                | After a typing pause, 250 ms by default  |
| `x-interact:model.live.debounce.400ms="name"` | After a 400 ms typing pause              |
| `x-interact:model.blur="name"`                | When the field loses focus               |
| `x-interact:model.change="name"`              | On the native change event               |

All modes update the browser draft immediately. Debounce delays the server
request, not local typing. An action captures the latest bound controls even
when a field has not blurred or its debounce has not expired.

Choose one timing mode. Only `.live` accepts `.debounce`, optionally followed by
whole milliseconds from `0ms` through `60000ms`. `.debounce` without a duration
uses 250 ms. Unknown, repeated, or conflicting modifiers fail at initialization.

Use ordinary Alpine `x-model` for browser-only preferences, such as opening a
menu. Interact's binding additionally coordinates server updates, PHP type
conversion, and draft reconciliation. Use one model binding per control;
do not combine `x-model` and `x-interact:model` on the same element.

## Text fields and textareas

The first example uses a text input. For longer text, declare writable string
state named `bio` and bind a textarea:

```php [Inside the island class]
#[State(writable: true)]
public string $bio = '';
```

```php [Inside the element]
<?= $this->Form->control('bio', [
    'type' => 'textarea',
    'label' => 'About you',
    'value' => $bio,
    'x-interact:model.blur' => 'bio',
]) ?>
```

`State` is imported from `Interact\Attribute`, as in the complete class above.
The remaining variations add properties to that class and controls to its form.

## Select one option

Use a writable string for a select whose option values are strings:

```php [Inside the island class]
#[State(writable: true)]
public string $country = '';
```

```php [Inside the element]
<?= $this->Form->control('country', [
    'type' => 'select',
    'label' => 'Country',
    'options' => ['BE' => 'Belgium', 'NL' => 'Netherlands'],
    'empty' => 'Choose a country',
    'value' => $country,
    'x-interact:model.change' => 'country',
]) ?>
```

Changing the selection synchronizes immediately. Use this for dependent choices;
the [forms guide](/guide/forms#add-dependent-or-conditional-fields) demonstrates
loading new city options and clearing the previous city on the server.

## Radio buttons

A radio group binds one selected value to one scalar property. Keep the same
native field name and model path across the group's options:

```php [Inside the island class]
#[State(writable: true)]
public string $format = 'remote';
```

```php [Inside the element]
<?= $this->Form->radio('format', [
    'remote' => 'Online',
    'studio' => 'In person',
], [
    'value' => $format,
    'x-interact:model.change' => 'format',
]) ?>
```

Choosing **In person** sets `$format` to `'studio'`. Unchecked radios do not
overwrite the selected value when an action captures the form.

## A boolean checkbox

A single checkbox binds its checked state to a boolean:

```php [Inside the island class]
#[State(writable: true)]
public bool $business = false;
```

```php [Inside the element]
<?= $this->Form->control('business', [
    'type' => 'checkbox',
    'label' => 'Book for a company',
    'checked' => $business,
    'x-interact:model.change' => 'business',
]) ?>
```

Checking it sends `true`; unchecking it sends `false`. Use this state to render
conditional fields in PHP or to show local feedback through Alpine.

## Multiple checkboxes

Use an array for a checkbox group. Every checkbox binds to the same array path:

```php [Inside the island class]
/**
 * Selected optional workshop extras.
 *
 * @var list<string>
 */
#[State(writable: true)]
public array $extras = [];
```

```php [Inside the element]
<?= $this->Form->multiCheckbox('extras', [
    'materials' => 'Printed materials',
    'recording' => 'Session recording',
], [
    'val' => $extras,
    'x-interact:model.change' => 'extras',
]) ?>
```

Selecting both options produces `['materials', 'recording']`. Unchecking an
option removes its value; unchecking everything produces `[]`. These are native
input values, so checkbox option values are strings.

The **Workshop planner** at `/playground` demonstrates checkbox groups,
conditional company fields, and server validation. The
[forms guide](/guide/forms) explains validation and dependent choices.

## Multiple selects

A multiple select also uses array state. With the preceding `extras` property,
use this control **instead of** the checkbox group:

```php [Inside the element]
<?= $this->Form->control('extras', [
    'type' => 'select',
    'label' => 'Optional extras',
    'multiple' => true,
    'options' => ['materials' => 'Printed materials', 'recording' => 'Recording'],
    'value' => $extras,
    'x-interact:model.change' => 'extras',
]) ?>
```

The array contains all selected option values. Clearing the selection produces
an empty array.

## Numbers and empty values

Declare the PHP type you need. Numeric strings from native controls are converted
using the declared `int` or `float` metadata:

```php [Inside the island class]
#[State(writable: true)]
public ?int $attendees = null;
```

```php [Inside the element]
<?= $this->Form->control('attendees', [
    'type' => 'number',
    'label' => 'Attendees',
    'min' => 0,
    'value' => $attendees,
    'x-interact:model.change' => 'attendees',
]) ?>
```

`0` remains zero; an empty nullable numeric field becomes `null`. HTML constraints
are user feedback. PHP still checks types, and application validation must check
allowed ranges and choices.

## Nested values

Declare the owning array as writable and initialize its shape:

```php [Inside the island class]
/**
 * Editable address fields for the preview.
 *
 * @var array{address: array{city: string}}
 */
#[State(writable: true)]
public array $form = ['address' => ['city' => '']];
```

```php [Inside the element]
<?= $this->Form->control('form.address.city', [
    'label' => 'City',
    'value' => $form['address']['city'],
    'x-interact:model' => 'form.address.city',
]) ?>
```

This writes a nested array value. It does not transport an entity or call the
Table layer. Keep entities in PHP through `viewData()` and map drafts explicitly
when [validating and saving](/guide/forms).

## Custom Alpine inputs

An `x-modelable` wrapper exposes a local property that Interact can bind. With
the earlier writable `country` property, this small wrapper works with the
Composer bundle's CSP runtime:

```php [Inside the element]
<div
    x-data="{ selection: '' }"
    x-modelable="selection"
    x-interact:model.change="country"
>
    <?= $this->Form->control('country', [
        'type' => 'select',
        'label' => 'Country',
        'options' => ['BE' => 'Belgium', 'NL' => 'Netherlands'],
        'empty' => 'Choose a country',
        'x-model' => 'selection',
    ]) ?>
</div>
```

Here the inner control uses Alpine's local model; the outer wrapper connects
that value to PHP. Custom `.change` bindings synchronize exposed-value changes;
`.live` debounces them; `.blur` waits until focus leaves the whole wrapper.
Accepted PHP values update the wrapper without causing another request.

For a library-managed input, use its API to update the exposed value. See the
[enhanced-select example](/guide/javascript-widgets#try-an-enhanced-select) for
initialization, cleanup, and protected widget markup.

## File inputs

Files bind to a declared `#[Upload]` property, using `x-interact:model` without
timing modifiers. Selection starts staging immediately, unlike a deferred text
draft. The file stays a server-side uploaded-file object; it is not JSON state.

```php [Inside an upload island's element]
<?= $this->Form->control('document', [
    'type' => 'file',
    'label' => 'Document',
    'x-interact:model' => 'document',
]) ?>
```

Follow the [upload guide](/guide/uploads) for the required property declaration,
multiple files, limits, validation, and explicit saving.

## Drafts, disabled controls, and validation

Newer edits survive an older response, and accepted values refresh surviving
controls. Disabled controls are excluded from capture. A custom wrapper's
`disabled` attribute also suspends automatic synchronization; use the library's
disable API when the widget must become noninteractive. A queued live timer
checks current disabled state and island ownership before sending.

Synchronization never implies database saving. Render field errors through
FormHelper or `$server.errors`; see [forms and validation](/guide/forms).
Use distinct stable IDs for repeated fields. Bindings resolve to their nearest
island, so parent forms do not capture a nested child's drafts.
