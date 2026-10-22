---
title: 'Renderless draft saves'
description: Save article drafts with CakePHP and FormHelper while retaining the current DOM.
---

# Renderless draft saves

This editor explicitly saves an article draft through CakePHP without rebuilding
the form after each successful action. Alpine counters display accepted state;
PHP counters show the values from the most recent render.

Complete [installation](/installation) first. Reuse the `form_articles` schema and
`FormArticlesTable` from the [article form](/examples/article-form). The demo uses
`TestApp`; change class namespaces to `App` in your application. Configure a
current editor identity through your application's authentication middleware and
adapt `authorize()` to its identity type. Run the [demo](/examples#run-the-demo)
at `/renderless`.

## Files

::: code-group

<<< @/../tests/test_app/src/Island/DraftEditor.php [Island]

<<< @/../tests/test_app/templates/element/Interact/draft_editor.php [Element]

```php [Mount]
<?= $this->Interact->render('draft-editor', key: 'renderless-draft') ?>
```

:::

## Try it

1. Edit the title and save repeatedly. The live save count advances; the PHP
   count and DOM stay in place.
2. Submit an empty title. FormHelper renders the failed entity's validation
   errors while preserving the submitted draft.
3. Correct the title and save. Interact renders once to clear those errors.
   Later successful saves become renderless again.
4. Choose **Refresh view** to render current PHP values without saving again.
5. Choose **Send signal** for conditional `skipRender()`, or **Send five signals
   and render** for a normal rendering action.

FormHelper takes values and errors from its entity context; no per-control
`value` mapping is necessary. Entities remain request-local. `patchEntity()`
allowlists editable fields and `save()` explicitly disables association saves.
The server-owned article ID returns in signed state so subsequent saves update
the same record. Saving and validation still belong to the application.

This demo grants its editor role access to all demo drafts. In an application
with per-record access, authorize the current article on every request before
loading or saving it. A signed ID does not establish current permission. Keep
access-scoped queries in a named Table finder.

The same form protection, loading feedback, keyboard submission, newer-draft
preservation, and explicit retry rules apply to [renderless actions](/guide/actions#renderless-actions).
