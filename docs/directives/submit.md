---
title: 'x-interact:submit'
description: Submit HTML forms through island actions and prevent repeated submissions.
---

# x-interact:submit

`x-interact:submit` handles a form submission without reloading the page.
Its expression typically calls a PHP island action.

<span id="submit-an-editable-record"></span>

## Usage

For writable `title` state and an exposed `save()` action:

::: code-group

```html [HTML]
<form id="article-draft-form" x-interact:submit="$server.call('save')">
  <label for="title">Title</label>
  <input
    id="title"
    name="title"
    type="text"
    required
    x-interact:model="title"
  />
  <button type="submit">Save article</button>
</form>
```

```php [FormHelper]
<?= $this->Form->create(null, [
    'url' => false,
    'id' => 'article-draft-form',
    'x-interact:submit' => '$server.call("save")',
]) ?>
    <?= $this->Form->control('title', [
        'label' => 'Title',
        'value' => $title,
        'required' => true,
        'x-interact:model' => 'title',
    ]) ?>
    <?= $this->Form->button('Save article') ?>
<?= $this->Form->end() ?>
```

:::

The expression runs in Alpine scope and must include the call. `save()` needs
[`#[Action]`](/guide/actions). The application owns validation and persistence;
see the complete [article form](/examples/article-form).

<span id="prevent-repeated-submission"></span>

## Submission protection

Interact blocks repeated submissions of the same form until its expression
settles, including time queued behind another request. It temporarily disables
owned submit buttons and sets the form's `aria-busy` to `true`. Inputs remain
editable; newer edits survive the response.

Success, validation feedback, and request failures release the protection.
Application-owned disabled states are preserved. Other forms and nested islands
remain independent. Stable unique form IDs allow locks to follow replacements.

<span id="preview-without-saving"></span>

## Synchronization without saving

Use `x-interact:submit="$server.sync()"` for a preview that only sends current
state and rerenders. No application action or automatic save is introduced.

<span id="pass-action-arguments"></span>

## Action arguments

Pass JSON-compatible arguments in the expression:

::: code-group

```html [HTML]
<form x-interact:submit="$server.call('save', 'publish')">
  <!-- Bound editor controls. -->
  <button type="submit">Publish</button>
</form>
```

```php [FormHelper]
<?= $this->Form->create(null, [
    'url' => false,
    'x-interact:submit' => '$server.call("save", "publish")',
]) ?>
    <!-- Bound editor controls. -->
    <?= $this->Form->button('Publish') ?>
<?= $this->Form->end() ?>
```

:::

This assumes a declared action such as `save(string $mode)`. Validate allowed
modes and authorize any record arguments in PHP.

<span id="keep-native-validation-and-keyboard-submission"></span>

## Native validation and external buttons

Browser constraints such as `required` still apply. Enter and `requestSubmit()`
use the protected submit path. Direct `form.submit()` bypasses submit events.
An external submit button participates when it belongs to the same island:

```html
<button type="submit" form="article-draft-form">Save article</button>
```

<span id="show-feedback"></span>

## Feedback

Use [`$server.loading()`](/reference/server#loading) for delayed visual feedback
and [`$server.error`](/guide/failures) for request failures. Application validation
messages use FormHelper or `$server.errors`.

<span id="custom-javascript-submission-functions"></span>

## Custom submission functions

Return the request promise from an Alpine method so protection lasts until it
settles:

```js
submitArticle() {
  return this.$server.call('save');
}
```

Register this method in an [Alpine object](/guide/alpine#access-server-from-a-javascript-file),
attach it inside the island, and use `x-interact:submit="submitArticle()"`.

<span id="supported-declaration"></span>

## Options

The directive requires a native form and a non-empty expression. It accepts no
modifiers. Do not add a second `@submit` handler to the same form.

Ordinary `$server.call()` buttons remain repeatable unless the application
chooses to disable them. Browser protection does not provide server-side
exactly-once execution or automatic retries.
