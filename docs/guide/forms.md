---
title: Forms and validation
description: Keep editable drafts in an island and use normal CakePHP authorization, validation, and saving.
---

# Forms and validation

Edit an article title, display a validation error, and save explicitly through
CakePHP's ORM. [Run the demo](/examples) and open `/` to try it: clear an article
title to see the browser’s required-field check, then enter a valid title and save.

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

Here is a compact version of the editor element. It receives the server-only
`article` entity through `viewData()`, the declared draft and ID, and the reserved
`errors` variable:

```php [templates/element/Interact/article_editor.php]
<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $article
 * @var int $articleId
 * @var string $title
 * @var array<string, list<string>> $errors
 */
?>
<?= $this->Form->create($article, [
    'url' => false,
    'id' => 'article-form-' . (string)$articleId,
    'idPrefix' => 'article-' . (string)$articleId,
    'x-interact:submit' => '$server.call("save")',
]) ?>
    <?= $this->Form->control('title', [
        'label' => 'Article title',
        'value' => $title,
        'x-interact:model' => 'title',
        'templates' => [
            'error' => '<div id="{{id}}" role="alert">{{content}}</div>',
        ],
    ]) ?>
    <?php foreach ($errors['_form'] ?? [] as $message): ?>
        <p role="alert"><?= (string)h($message) ?></p>
    <?php endforeach; ?>
    <?= $this->Form->button('Save article') ?>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
<?= $this->Form->end() ?>
```

`create($article)` gives FormHelper its normal entity context. It renders field
errors, associates them with the input, and derives native validation constraints.
The `error` template adds an accessible alert role; FormHelper still owns message
escaping and the error ID. The island view disables FormHelper's inline custom
validity handlers so native browser validation works under a strict CSP.

The explicit `value` uses the current island draft, including invalid values that
CakePHP's marshaller did not assign to the entity. `idPrefix` keeps fields distinct
between editors, and the form's stable `id` preserves its identity through updates.
Use an instance-specific prefix if the same record appears more than once.
`url => false` omits the form action because Interact handles submission.

Typing alone sends no request. A valid submission flushes the current control
value before calling `save()`. Browser constraints improve feedback; the server
must validate independently because clients can bypass them.

## Validate and save in the action

The demo loads the record, patches only the allowed `title` field, and uses the
Table's normal `save()`. On failure, pass CakePHP's errors directly:

```php [Inside ArticleEditor::save()]
$articles = $this->articles();
$article = $articles->patchEntity(
    $articles->get($this->articleId),
    ['title' => $this->title],
    ['fields' => ['title']],
);

$this->article = $article;
if (!$articles->save($article)) {
    $this->setErrors($article->getErrors() ?: [
        '_form' => ['Unable to save the article.'],
    ]);

    return;
}

$this->saved = true;
$this->dispatch('article-saved', ['id' => $this->articleId]);
```

Keep the entity in a protected property and return that **same instance** from
`viewData()`. Loading a fresh entity after validation would lose its errors:

```php [ArticleEditor — transient entity and view data]
/**
 * @var \Cake\Datasource\EntityInterface|null Entity context for this request only.
 */
protected ?EntityInterface $article = null;

/**
 * Preserve the entity's validation feedback for FormHelper without transporting it.
 *
 * @return array{article: \Cake\Datasource\EntityInterface}
 */
public function viewData(): array
{
    return ['article' => $this->article ?? $this->articles()->get($this->articleId)];
}
```

Import `Cake\Datasource\EntityInterface` in the island class. The protected
property is not declared state and never enters the snapshot. Each request gets a
fresh island, so this entity is retained only for its current render.

FormHelper reads the entity's original errors. Separately, `setErrors()` supplies
`$errors` and `$server.errors`, selects HTTP 422, and suppresses success events.
Neither mechanism runs validation or saves automatically. If only the entity
has errors, FormHelper can display them, but Interact does not infer a failed
response from arbitrary view data. For a failed save without entity errors, the
example supplies an application-owned `_form` message.

## Use custom or nested errors

Application-defined message lists remain supported, including alongside entity
errors:

```php
$this->setErrors([
    ...$this->article->getErrors(),
    '_form' => ['Please review the highlighted fields.'],
]);
```

A field can contain a list of strings or strings keyed by CakePHP rule names.
For example, `['title' => ['_empty' => 'A title is required.']]` becomes
`['title' => ['A title is required.']]`. Nested association errors become dotted
paths such as `comments.0.body`; rule names are omitted. The original entity is
unchanged. See the [validation error contract](/api#validation-errors) for exact
accepted shapes and clearing behavior.

:::: details Full editor, element, and Table implementation

These are the tested files used by the demo. Its element includes loading,
draft, and success feedback.

::: code-group

<<< @/../tests/test_app/src/Island/ArticleEditor.php [Editor island]

<<< @/../tests/test_app/templates/element/Interact/article_editor.php [Element]

<<< @/../tests/test_app/src/Model/Table/ArticlesTable.php [Table and validation]

:::

::::

## Add dependent or conditional fields

Open `/` for country and city choices. The `dependent-selects` island:

1. Sends country changes using `x-interact:model.change`.
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
`x-interact:model` and sends their declared writable values as JSON.
FormHelper's hidden fields are not transported as island state. CSRF remains
protected through the update request's token header and CakePHP middleware.

Validation still runs in the application's Table and action. Forms created with
`create(null)` have no entity context; render their application errors from
`$errors` explicitly. Using FormHelper does not add automatic FormProtection field-hash validation to
the JSON update protocol. Keep protection on ordinary form endpoints and scope
update-route middleware appropriately; do not disable application protections.

The current layer does not provide file uploads, entity transport, or form-plugin
integration. Use explicit state and actions. Navigation and automatic saving are not
part of the form's synchronization behavior.

**Next:** [Refresh a parent listing after a save](/guide/nesting).
