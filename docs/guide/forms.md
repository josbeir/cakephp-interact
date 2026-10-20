---
title: Forms and validation
description: Keep editable drafts in an island and use normal CakePHP authorization, validation, and saving.
---

# Forms and validation

Edit an article title, display a validation error, and save explicitly through
CakePHP's ORM. [Run the demo](/examples) and open `/` to try it: clear an article
title and save, then enter a valid title and save again.

This example uses the demo's `Articles` table and fixture identity. In your
application, keep your own authentication integration, access policies, Table
validation, and application rules.

## Separate the record from the draft

The editor receives a mount-only record ID and declares an editable title:

```php [ArticleEditor — property declarations]
#[Prop]
public int $articleId;

#[State(writable: true)]
public string $title = '';
```

`authorize(IslandContext $context)` checks the current identity and record
ownership on the initial mount **and every update**. `mount()` then loads the
title on the first render only. Later requests restore the user's draft, rather
than replacing it with the current database value.

Entities stay on the server. A signed article ID establishes snapshot integrity;
your current authorization checks establish access.

<span id="render-a-normal-form"></span>

## Render with FormHelper

Here is a compact version of the editor element. It receives `articleId`, `title`,
and the reserved `errors` variable from the island:

```php [templates/element/Interact/article_editor.php]
<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var int $articleId
 * @var string $title
 * @var array<string, list<string>> $errors
 */
$errorId = 'article-' . (string)$articleId . '-title-errors';
?>
<?= $this->Form->create(null, [
    'url' => false,
    'idPrefix' => 'article-' . (string)$articleId,
    'data-interact-submit' => 'save',
]) ?>
    <?= $this->Form->control('title', [
        'type' => 'text',
        'label' => 'Article title',
        'value' => $title,
        'data-interact-model' => 'title',
        'aria-invalid' => isset($errors['title']) ? 'true' : null,
        'aria-describedby' => isset($errors['title']) ? $errorId : null,
    ]) ?>
    <?php if (isset($errors['title'])): ?>
        <div id="<?= (string)h($errorId) ?>" role="alert">
            <?php foreach ($errors['title'] as $message): ?>
                <p><?= (string)h($message) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?= $this->Form->button('Save article', [':disabled' => '$server.busy']) ?>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
<?= $this->Form->end() ?>
```

`create(null)` starts a context-less form: the island owns the draft, and
`value` supplies it on every render. FormHelper escapes values and generates
labels, names, and IDs. `idPrefix` keeps the fields distinct between editors;
`url => false` omits the form action because Interact handles submission.
FormHelper is available through CakePHP's normal helper loading.

The title uses deferred binding: typing alone sends no request. Submitting flushes
the current control value before calling `save()`. Use a unique error ID for
each rendered record; if you render one record more than once, add an
instance-specific prefix.

## Validate and save in the action

The demo's `save()` action loads the record, patches only the allowed `title`
field, and calls the Table's normal `save()`. It converts validation failures to
`array<string, list<string>>` and calls:

```php
$this->setErrors(['title' => ['A title is required.']]);
```

The response uses HTTP 422 and still renders the element with its draft and
errors. This is expected validation feedback. Successful saves set a confirmation
flag and dispatch `article-saved`; failure suppresses success events.

`setErrors()` renders application feedback; it does not run validation itself.
For a database failure without a field error, the demo supplies a generic message.

:::: details Full editor and Table implementation

These are the tested files used by the demo. Its element has additional loading,
draft, and success feedback, with the same field-error associations.

::: code-group

<<< @/../tests/test_app/src/Island/ArticleEditor.php [Editor island]

<<< @/../tests/test_app/src/Model/Table/ArticlesTable.php [Table and validation]

:::

::::

## Add dependent or conditional fields

Open `/` for country and city choices. The `dependent-selects` island:

1. Sends country changes using `data-interact-update="change"`.
2. Clears `cityId` in `updated()` when `country` changes.
3. Loads city choices through the Table's `inCountry` finder in `viewData()`.
4. Rechecks city membership in its `confirm()` action.

Rendered options are a convenience, not authorization. A forged city ID still
needs rejection on the server.

:::: details Full dependent-select implementation

::: code-group

<<< @/../tests/test_app/src/Island/DependentSelects.php [Island]

<<< @/../tests/test_app/src/Model/Table/CitiesTable.php [Finders]

<<< @/../tests/test_app/templates/element/Interact/dependent_selects.php [Element]

:::

::::

For conditional fields, open the workshop planner at `/playground`. Toggling a
business booking reveals company inputs, and the PHP action validates the
required company value. Hiding a control in the browser does not replace those
server checks.

## Keep protection and persistence explicit

FormHelper generates the markup; Interact reads controls carrying
`data-interact-model` and sends their declared writable values as JSON.
FormHelper's hidden fields are not transported as island state. CSRF remains
protected through the update request's token header and CakePHP middleware.

Validation still runs in the application's Table and action. The example renders
`$errors` explicitly because a context-less form has no entity validation context.
Using FormHelper does not add automatic FormProtection field-hash validation to
the JSON update protocol. Keep protection on ordinary form endpoints and scope
update-route middleware appropriately; do not disable application protections.

The current layer does not provide file uploads, entity transport, or form-plugin
integration. Use explicit state and actions. Navigation and automatic saving are not
part of the form's synchronization behavior.

**Next:** [Refresh a parent listing after a save](/guide/nesting).
