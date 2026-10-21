---
title: 'x-interact:submit'
description: Submit CakePHP forms through island actions, prevent repeated submissions, and keep native validation and editable drafts.
---

# x-interact:submit

Handle a native form submission through the owning island. Interact prevents
normal navigation, captures the latest bound fields, and protects the form
while the submission waits or runs.

## Submit an editable record

For an existing island with writable `title` state and a declared `save()` action:

```php [Element]
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

`save()` must be marked `#[Action]`. It owns authorization, application validation,
and persistence. The complete [entity editing example](/guide/forms) shows that
PHP implementation and uses the entity as FormHelper's validation context.

The directive is an **Alpine expression**, so include the call:
`$server.call('save')`. It is not a bare PHP method name. Bound inputs use
[literal model paths](/directives/model) instead.

## Prevent repeated submission

From acceptance until response application completes, Interact:

- Blocks another submission of the same form.
- Temporarily disables that form's owned native submit buttons.
- Sets the form's `aria-busy` to `true`.
- Leaves model fields editable and preserves edits made while waiting.

The protection includes time queued behind another request. It releases after
an accepted response, validation feedback, or request failure. Application-owned
disabled states are preserved, including new disabled states rendered by PHP.

Other forms remain independent. Parent islands do not lock a nested island's
submit buttons. Use stable, unique native form IDs when replacing forms so an
outstanding lock can follow the same form identity.

## Preview without saving

Use `$server.sync()` when submission should only synchronize state and rerender:

```php [Inside the element]
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
```

This assumes a writable `name` property. The [model example](/directives/model#preview-a-name)
includes its class and mount call. No application action or automatic save is
introduced by synchronization.

## Pass action arguments

The submit expression can pass JSON-compatible arguments:

```php [Inside an article editor element]
<?= $this->Form->create($article, [
    'url' => false,
    'x-interact:submit' => '$server.call("save", "publish")',
]) ?>
    <!-- The editor's bound controls. -->
    <?= $this->Form->button('Publish') ?>
<?= $this->Form->end() ?>
```

Here the application supplies `$article` through `viewData()` and declares an
action such as `save(string $mode)`. Validate the allowed mode in PHP. When an
argument identifies a record, authorize that record too; a rendered choice does
not make a client-supplied value trustworthy.

## Keep native validation and keyboard submission

FormHelper's `required`, `min`, `max`, and other native constraints still work.
If browser validation fails, no submit event is accepted and no island request
is made. Server validation remains necessary.

Pressing Enter and calling `form.requestSubmit()` use the same protected submit
path. Direct `form.submit()` bypasses native submit events and the directive.
Associated submit buttons outside a form are supported when they belong to the
same island and use the form's ID:

```html
<button type="submit" form="article-draft-form">Save article</button>
```

This button submits the form from the first example and participates in its
temporary lock.

## Show feedback

Inside the form or its owning island, use the normal bridge for feedback:

```html
<span role="status" x-show="$server.loading('save')" x-cloak>Saving…</span>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
```

Loading feedback waits 200 ms by default to avoid flashing on fast requests;
submission protection begins immediately. With `x-cloak`, include
`[x-cloak] { display: none !important; }` in your stylesheet.

Application validation feedback is available through the entity, PHP `$errors`,
or `$server.errors`, depending on the application's form setup. A renderable
HTTP 422 releases the form normally. Request failures use `$server.error`; see
[failures and recovery](/guide/failures) for recovery choices.

## Custom JavaScript submission functions

An Alpine function can perform additional application work, provided it returns
the request promise. For example, register this object in an external script
loaded before Interact's runtime:

```js [JavaScript]
// webroot/js/article-tools.js
document.addEventListener('alpine:init', () => {
  window.Alpine.data('articleTools', () => ({
    submitArticle() {
      return this.$server.call('save');
    },
  }));
});
```

Attach `x-data="articleTools"` to an ancestor inside the island, then set
`'x-interact:submit' => 'submitArticle()'` in `Form->create()`. The returned
promise keeps the form protected until the operation settles. The
[Alpine guide](/guide/alpine#access-server-from-a-javascript-file) shows the
registration script and layout order.

## Supported declaration

Use the directive on a native `<form>` inside an island, with a non-empty
expression and no modifiers. FormHelper is the conventional CakePHP way to
produce this markup; handwritten native forms work too.

Use one submit handler on the form. Do not also attach `@submit` or
`@submit.prevent`, which would create an independent submission path. Ordinary
`@click="$server.call('save')"` buttons remain repeatable unless the application
disables them. Browser submission protection does not guarantee server-side
exactly-once execution, and failed actions are never automatically retried.
