---
title: Save and redirect
description: Validate an entity, save it, and navigate to its normal CakePHP page.
---

# Save and redirect

This example creates an article with CakePHP model validation and redirects to
its detail page. Failed validation keeps the entity and its errors in the form.

Use the [installation setup](/installation), then create the `articles` table
and `ArticlesTable` with the files below. Supply a normal CakePHP
route named `articles:view` accepting an `id` parameter, with its controller and
detail template. For protected records, authorize creation and viewing in the application.

::: code-group

<<< @/../tests/docs/app/src/Island/ArticleRedirect.php [Island]

<<< @/../tests/docs/app/templates/element/Interact/article_redirect.php [Element]

<<< @/../tests/docs/app/src/Model/Table/ArticlesTable.php [Table]

<<< @/../tests/docs/app/schema/articles.sql [Schema]

```php [Mount]
<?= $this->Interact->render('article-redirect') ?>
```

:::

`redirect()` skips the final island render. No redirect directive or browser
callback is needed. See [redirects](/guide/redirects-and-downloads) for routing defaults,
external URLs, history replacement, and lifecycle rules.

The larger demo at `/outcomes` also provides save-and-redirect controls, including
history replacement, alongside native downloads.
