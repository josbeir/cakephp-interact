---
title: 'Validation'
description: Render CakePHP entity errors and return field feedback with setErrors().
---

<span id="forms-and-validation"></span>

# Validation

Interact displays validation errors returned by your application. Validation
and saving use CakePHP's normal entity, Table, and service APIs.

<span id="validate-and-save-in-the-action"></span>

## setErrors()

Pass an entity's errors after a failed save:

```php
$this->setErrors($article->getErrors());
```

`setErrors()` supplies PHP `$errors` and browser `$server.errors`, selects a
renderable HTTP 422 response, and suppresses success events. It does not run
validation or save a record. See the complete [article form](/examples/article-form).

<span id="separate-the-record-from-the-draft"></span>
<span id="render-a-normal-form"></span>
<span id="render-with-formhelper"></span>

## FormHelper entity errors

Keep the failed entity in a protected property and return that same instance
from `viewData()`. FormHelper reads its validation errors when you pass it to
`Form->create()`.

```php
<?= $this->Form->create($article, [
    'url' => false,
    'x-interact:submit' => '$server.call("save")',
]) ?>
```

The entity stays on the server. Reloading a new entity after validation loses
the failed instance's errors. Interact does not infer failure from arbitrary
objects in `viewData()`; call `setErrors()` to set its response feedback too.

<span id="use-custom-or-nested-errors"></span>

## Custom and nested errors

```php
$this->setErrors([
    ...$article->getErrors(),
    '_form' => ['Please review the highlighted fields.'],
]);
```

Each field accepts message lists or CakePHP rule-to-message maps. For example,
`['title' => ['_empty' => 'A title is required.']]` becomes
`['title' => ['A title is required.']]`. Nested associations become dotted fields
such as `comments.0.body`; rule names are omitted. The entity is unchanged.

Use `_form` for an application-wide message, including a failed save without
field errors. Each call replaces response-local feedback; `setErrors([])` clears it.
The root maps non-empty string field names to arrays. Message strings retain
order and contents; their rule keys are discarded. Nested arrays extend paths
through field names or association indices. Dotted root keys are accepted, and
overlapping paths append in traversal order.

Empty arrays contribute no messages. Bare root strings, nulls, booleans, numbers,
objects, empty nested keys, and nesting beyond 32 child levels are rejected with
`InvalidArgumentException`; invalid input leaves previous feedback unchanged.
Custom errors are not attached to the entity. Attach them separately if
FormHelper should render them through that context.

## errors()

`errors(): array` returns normalized `array<string, list<string>>` feedback,
also available as PHP `$errors` and `$server.errors`.

## Rendering errors

For an island with writable `title` state:

::: code-group

```html [HTML]
<label for="title">Title</label>
<input
  id="title"
  name="title"
  type="text"
  x-interact:model="title"
  aria-describedby="title-errors"
/>
<ul id="title-errors" role="alert">
  <template
    x-for="(message, index) in ($server.errors.title || [])"
    :key="index"
  >
    <li x-text="message"></li>
  </template>
</ul>
```

```php [FormHelper]
<?= $this->Form->control('title', [
    'label' => 'Title',
    'value' => $title,
    'x-interact:model' => 'title',
]) ?>
```

:::

The FormHelper version assumes the current entity is its form context. A form
created with `null` has no entity errors; render `$errors` explicitly or use the
browser binding shown in the HTML tab.

<span id="add-dependent-or-conditional-fields"></span>

## Dependent and conditional fields

Reset dependent values in `updated()` and recheck allowed combinations in the
action. Hidden inputs and rendered choices are not authorization. The
[dependent fields example](/examples/dependent-fields) checks city membership
through a Table finder.

<span id="keep-protection-and-persistence-explicit"></span>

## Native form protection

Native `required`, `min`, and `max` constraints still apply. Keep server validation
as well. Whole-form models exclude CakePHP's `_csrfToken`, `_Token` subtree, and
`_method` bookkeeping. Other hidden inputs can bind; hidden fallbacks cannot
overwrite their checkbox, radio, or multiple-select value. FormProtection's field
hash is not automatically validated by the JSON protocol.
CSRF uses CakePHP's middleware and the request token header. See
[security and deployment](/security) for scoped middleware.
