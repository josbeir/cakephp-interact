---
title: 'x-interact:model'
description: Bind HTML inputs to PHP properties and configure when edits reach the server.
---

<span id="preview-a-name"></span>

# x-interact:model

`x-interact:model` binds a control to writable state, or a native form to a writable
array draft using its controls' names.

Declare the property as writable state. Import `State` from `Interact\Attribute`:

```php
#[State(writable: true)]
public string $title = '';
```

::: code-group

```html [HTML]
<label for="title">Title</label>
<input id="title" name="title" type="text" x-interact:model="title" />
```

```php [FormHelper]
<?= $this->Form->control('title', [
    'label' => 'Title',
    'value' => $title,
    'x-interact:model' => 'title',
]) ?>
```

:::

The attribute contains a literal property path, such as `title` or
`form.address.city`. Props and state without `writable: true` cannot receive
model edits. See [Properties](/guide/state) for declarations.

Alpine's `x-model` binds to local Alpine state; `x-interact:model` binds to a PHP
draft and manages its synchronization. Choose one per native control. Use local
Alpine state for values PHP does not need, such as an expanded panel or a local
filter. See [local state and PHP state](/guide/alpine#local-state-and-php-state).

## Whole forms

Declare writable array state and initialize its draft in application code:

```php
#[State(writable: true)]
public array $data = [
    'title' => '',
    'body' => '',
    'comments' => [['body' => '']],
];
```

::: code-group

```html [HTML]
<form x-interact:model="data" x-interact:submit="$server.call('save')">
  <label for="draft-title">Title</label>
  <input id="draft-title" name="title" type="text" />
  <label for="draft-body">Body</label>
  <textarea id="draft-body" name="body"></textarea>
  <label for="draft-comment">First comment</label>
  <input id="draft-comment" name="comments[0][body]" type="text" />
  <button type="submit">Save</button>
</form>
```

```php [FormHelper]
<?= $this->Form->create(null, [
    'url' => false,
    'x-interact:model' => 'data',
    'x-interact:submit' => '$server.call("save")',
]) ?>
    <?= $this->Form->control('title', ['id' => 'draft-title', 'label' => 'Title']) ?>
    <?= $this->Form->control('body', ['id' => 'draft-body', 'type' => 'textarea', 'label' => 'Body']) ?>
    <?= $this->Form->control('comments.0.body', ['id' => 'draft-comment', 'label' => 'First comment']) ?>
    <?= $this->Form->button('Save') ?>
<?= $this->Form->end() ?>
```

:::

The form expression is a literal array path, including nested paths such as
`editor.data`. Control names resolve relative to it:

| Native name                              | State path with `x-interact:model="data"`                 |
| ---------------------------------------- | --------------------------------------------------------- |
| `title`                                  | `data.title`                                              |
| `comments[0][body]` or `comments.0.body` | `data.comments.0.body`                                    |
| `topics[]`                               | `data.topics` (list-valued checkboxes or multiple select) |

Use explicit indices for repeated rows. Ambiguous names such as
`comments[][body]`, malformed brackets, and unsafe paths are rejected. Terminal
`[]` and existing array-valued leaves identify checkbox groups. A nested boolean
checkbox stays boolean even though its parent state is an array. Number inputs
inside the array retain strings; CakePHP marshalling handles domain types.

Timing modifiers on the form provide defaults for inferred bindings, for example
`x-interact:model.live.debounce.400ms="data"`. With no modifiers, edits are
deferred. An explicit control-level binding takes precedence and uses its own
**absolute** path and modifiers:

```html
<form x-interact:model.blur="data">
  <input name="title" />
  <input name="search" x-interact:model.live="search" />
</form>
```

This assumes writable array `data` and writable string `search` state.

Controls start from declared browser state. Missing leaves use the normal empty
control values; server-rendered entity values are not imported into the draft.
Initialize `$data` yourself and supply the failed entity to FormHelper for
validation errors. The [article and comments example](/demo#forms)
shows `patchEntity($entity, $this->data, $options)` and an explicit save.

Form binding follows `form.elements`, including external controls associated by
`form="id"` in the same island. Added, removed, renamed, and reassigned controls
are tracked. Unnamed controls, buttons, files, Alpine `x-model` controls,
`x-modelable` widget interiors, ignored regions, and child islands are excluded.
Disabled controls, including disabled fieldsets, are excluded from capture.

Ordinary hidden fields bind too. Hidden fallbacks sharing a checkbox, radio, or
multiple-select path cannot overwrite its value. CakePHP's `_csrfToken`, `_Token`
subtree, and `_method` remain native bookkeeping and are excluded.

[`x-interact:submit`](/directives/submit) separately invokes the action and
protects native submissions. It does not collect unbound controls on its own.
Form binding uses the existing JSON update protocol and writable-state checks;
it does not authorize association IDs, marshal entities, or save records.

<span id="choose-when-to-send-edits"></span>

## Update timing

| Binding                | Request timing                           |
| ---------------------- | ---------------------------------------- |
| `x-interact:model`     | With the next action or `$server.sync()` |
| `.live`                | After a typing pause, 250 ms by default  |
| `.live.debounce.400ms` | After a 400 ms typing pause              |
| `.blur`                | When the input loses focus               |
| `.change`              | On its native change event               |

All modes update local browser state immediately. An action captures current
control values even before blur or an outstanding debounce. Synchronization does
not save a database record.

Use one timing mode. `.debounce` is valid only with `.live`, optionally followed
by `0ms` through `60000ms`. Without a duration it uses the [configured debounce default](/configuration#javascript-configuration), initially 250 ms. Unknown, repeated,
or conflicting modifiers are errors.

::: code-group

```html [HTML]
<input
  type="search"
  aria-label="Search"
  x-interact:model.live.debounce.400ms="search"
/>
```

```php [FormHelper]
<?= $this->Form->control('search', [
    'type' => 'search',
    'label' => false,
    'aria-label' => 'Search',
    'value' => $search,
    'x-interact:model.live.debounce.400ms' => 'search',
]) ?>
```

:::

This assumes a writable string `search` property. See the
[search example](/demo#search) for a model-backed listing.

<span id="text-fields-and-textareas"></span>

## Textareas

For a writable string `bio` property:

::: code-group

```html [HTML]
<label for="bio">About you</label>
<textarea id="bio" name="bio" x-interact:model.blur="bio"></textarea>
```

```php [FormHelper]
<?= $this->Form->control('bio', [
    'type' => 'textarea',
    'label' => 'About you',
    'value' => $bio,
    'x-interact:model.blur' => 'bio',
]) ?>
```

:::

<span id="select-one-option"></span>

## Selects

For a writable string `country` property initialized to `''`:

::: code-group

```html [HTML]
<label for="country">Country</label>
<select id="country" name="country" x-interact:model.change="country">
  <option value="">Choose a country</option>
  <option value="BE">Belgium</option>
  <option value="NL">Netherlands</option>
</select>
```

```php [FormHelper]
<?= $this->Form->control('country', [
    'type' => 'select',
    'label' => 'Country',
    'options' => ['BE' => 'Belgium', 'NL' => 'Netherlands'],
    'empty' => 'Choose a country',
    'value' => $country,
    'x-interact:model.change' => 'country',
]) ?>
```

:::

`.change` sends the selected value immediately. For dependent choices, see
[country and city fields](/demo).

## Radio buttons

A radio group assigns one selected value to a scalar property. For a writable
string `format` property initialized to `'remote'`:

::: code-group

```html [HTML]
<label>
  <input
    type="radio"
    name="format"
    value="remote"
    x-interact:model.change="format"
  />
  Online
</label>
<label>
  <input
    type="radio"
    name="format"
    value="studio"
    x-interact:model.change="format"
  />
  In person
</label>
```

```php [FormHelper]
<?= $this->Form->radio('format', [
    'remote' => 'Online',
    'studio' => 'In person',
], [
    'value' => $format,
    'x-interact:model.change' => 'format',
]) ?>
```

:::

All options use the same native name and model path. Choosing **In person** sets
`format` to `'studio'`. Unchecked radios do not overwrite the selected value.

<span id="a-boolean-checkbox"></span>

## Boolean checkboxes

A single checkbox assigns `true` or `false`. Declare writable boolean `business`
state initialized to `false`:

::: code-group

```html [HTML]
<label>
  <input type="checkbox" name="business" x-interact:model.change="business" />
  Book for a company
</label>
```

```php [FormHelper]
<?= $this->Form->control('business', [
    'type' => 'checkbox',
    'label' => 'Book for a company',
    'checked' => $business,
    'x-interact:model.change' => 'business',
]) ?>
```

:::

<span id="multiple-checkboxes"></span>

## Checkbox groups

Multiple checkboxes add or remove their string values from an array. Declare
writable array `extras` state initialized to `[]`:

::: code-group

```html [HTML]
<label>
  <input
    type="checkbox"
    name="extras[]"
    value="materials"
    x-interact:model.change="extras"
  />
  Printed materials
</label>
<label>
  <input
    type="checkbox"
    name="extras[]"
    value="recording"
    x-interact:model.change="extras"
  />
  Session recording
</label>
```

```php [FormHelper]
<?= $this->Form->multiCheckbox('extras', [
    'materials' => 'Printed materials',
    'recording' => 'Session recording',
], [
    'val' => $extras,
    'x-interact:model.change' => 'extras',
]) ?>
```

:::

Both selected options produce `['materials', 'recording']`. Clearing all options
produces `[]`.

## Multiple selects

The same array property can use a multiple select instead:

::: code-group

```html [HTML]
<label for="extras">Optional extras</label>
<select id="extras" name="extras[]" multiple x-interact:model.change="extras">
  <option value="materials">Printed materials</option>
  <option value="recording">Session recording</option>
</select>
```

```php [FormHelper]
<?= $this->Form->control('extras', [
    'type' => 'select',
    'label' => 'Optional extras',
    'multiple' => true,
    'options' => ['materials' => 'Printed materials', 'recording' => 'Session recording'],
    'value' => $extras,
    'x-interact:model.change' => 'extras',
]) ?>
```

:::

<span id="numbers-and-empty-values"></span>

## Numbers

Declare `int` or `float` state for numeric conversion. A nullable numeric property
allows an empty input to become `null`:

```php
#[State(writable: true)]
public ?int $attendees = null;
```

::: code-group

```html [HTML]
<label for="attendees">Attendees</label>
<input
  id="attendees"
  name="attendees"
  type="number"
  min="0"
  x-interact:model.change="attendees"
/>
```

```php [FormHelper]
<?= $this->Form->control('attendees', [
    'type' => 'number',
    'label' => 'Attendees',
    'min' => 0,
    'value' => $attendees,
    'x-interact:model.change' => 'attendees',
]) ?>
```

:::

Zero remains `0`. HTML constraints provide browser feedback; application
validation must still check ranges and allowed choices.

## Nested values

Declare the owning array as writable and initialize its structure:

```php
/**
 * Editable address fields.
 *
 * @var array{address: array{city: string}}
 */
#[State(writable: true)]
public array $form = ['address' => ['city' => '']];
```

::: code-group

```html [HTML]
<label for="city">City</label>
<input
  id="city"
  name="form[address][city]"
  type="text"
  x-interact:model="form.address.city"
/>
```

```php [FormHelper]
<?= $this->Form->control('form.address.city', [
    'label' => 'City',
    'value' => $form['address']['city'],
    'x-interact:model' => 'form.address.city',
]) ?>
```

:::

## Custom Alpine inputs

A wrapper with `x-modelable` can expose a local value to Interact. For the earlier
writable `country` property:

```html
<div
  x-data="{ selection: '' }"
  x-modelable="selection"
  x-interact:model.change="country"
>
  <label for="widget-country">Country</label>
  <select id="widget-country" x-model="selection">
    <option value="">Choose a country</option>
    <option value="BE">Belgium</option>
    <option value="NL">Netherlands</option>
  </select>
</div>
```

The inner `x-model` binds local Alpine state. The wrapper connects it to PHP.
Custom `.change` bindings synchronize value changes; `.live` debounces them;
`.blur` waits until focus leaves the wrapper. Accepted PHP values update the
wrapper without sending another request.

Do not combine `x-model` and `x-interact:model` on the same element. Use JSON
values and keep library instances outside reactive state. See the
[enhanced select example](/demo#widgets).

## File inputs

Files require an [`#[Upload]` declaration](/guide/uploads), without timing modifiers:

::: code-group

```html [HTML]
<label for="document">Document</label>
<input id="document" name="document" type="file" x-interact:model="document" />
```

```php [FormHelper]
<?= $this->Form->control('document', [
    'type' => 'file',
    'label' => 'Document',
    'x-interact:model' => 'document',
]) ?>
```

:::

File selection starts staging immediately. Files are PHP objects rather than
JSON state. See [File uploads](/guide/uploads) for limits and validation.

<span id="drafts-disabled-controls-and-validation"></span>

## Disabled controls and responses

Disabled controls are excluded from capture. A custom wrapper's `disabled`
attribute also suspends automatic synchronization; disable the actual widget
through its library API when needed. Queued live timers recheck disabled state
and island ownership before sending.

Accepted responses update surviving controls while preserving newer edits and
focus. Use stable, unique IDs for repeated controls. Parent islands do not
capture nested child drafts. See [Validation](/guide/forms) for field errors.
