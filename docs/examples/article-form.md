---
title: 'Article form'
description: Bind article and comment drafts by native names, then marshal, validate, and save with CakePHP.
---

# Article form

This form binds an article and its new comments to writable array `$data`.
Native FormHelper names supply nested paths; the application patches a new
entity, validates it, and explicitly saves its comments.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations. The demo uses the `TestApp` namespace; change it to `App`
in the PHP classes and imports when copying into your own application.

Create the `form_articles` and `comments` tables with the schema tab or your normal
migration. The example uses CakePHP's generic entities. An application with its
own entities must allow access to the shown draft fields and `comments`
association. No entity is transported. Run it in the [demo](/examples#run-the-demo)
at `/forms` to use the FormHelper example.

## Files

::: code-group

<<< @/../tests/test_app/src/Island/Article/FormIsland.php [Island]

<<< @/../tests/test_app/templates/island/Article/form.php [Template]

<<< @/../tests/test_app/src/Model/Table/FormArticlesTable.php [Articles]

<<< @/../tests/test_app/src/Model/Table/CommentsTable.php [Comments]

<<< @/../tests/test_app/config/schema/form_articles.sql [Schema]

```php [Mount]
<?= $this->Interact->render('Article/Form') ?>
```

:::

## Behavior

`x-interact:model="data"` binds every eligible named control. FormHelper's
`comments.0.body` produces `name="comments[0][body]"`, which binds
`data.comments.0.body`. The published and approval checkboxes are booleans;
`topics[]` is a list. FormHelper's hidden fallbacks never overwrite these values.

`addComment()` and `removeComment()` capture current controls before changing the
application draft. Removing a row reindexes its names. These actions do not save.
Use stable application row keys when an editor needs identity across reordering.

An empty title, a title longer than 120 characters, or an empty comment fails
Table validation.

FormHelper reads values from its entity context, so controls need no per-field
`value`, `checked`, or `val` mappings. On ordinary renders, `viewData()` assigns
the draft to a new request-local entity with `patch()`. That assignment
performs no association loading, validation, or persistence. After a failed save,
FormHelper receives the failed entity and its original validation errors, while
`$data` preserves every submitted value. `setErrors()` supplies field feedback
such as `comments.0.body`. The response retains the original draft rather than
replacing it with a partially marshalled entity.

A valid save calls `patchEntity()` with explicit root and association fields,
then `save()` with explicit `Comments` persistence. Topics use a native JSON
column type; checkbox values use CakePHP's normal boolean marshalling. The action
dispatches `article-saved`. See [Events](/guide/events) for a listing listener.

## Existing records and association IDs

This public create-only example rejects article and comment IDs before
marshalling. Model binding does not authorize submitted IDs, and hiding an ID or
allowlisting editable fields does not grant access to that record. Existing-record
editors must authorize the article on every request and independently authorize
each supplied association ID before `patchEntity()`.

Initialize an existing-record draft in `mount()` from explicitly selected fields.
Keep association loading and access scopes in a named Table finder; execute it in
the island after authorization. Reload the entity for saving on later requests,
without overwriting the transported draft. Choose association persistence options
and deletion behavior explicitly in the application.

The remaining mapping is application-owned: initial draft selection, patchable
fields, association save options, and failed entity context for FormHelper. This
example needs no entity transport or restoration layer. A future entity
integration should be justified by repeated mapping in an existing-record editor,
not by introducing automatic persistence here.
