---
title: 'Article form'
description: Create an article with CakePHP entity validation and FormHelper errors.
---

# Article form

This form creates an article, renders entity validation errors, and announces a
successful save with an event.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

Create the `articles` table with the schema tab or your normal migration. The
example uses CakePHP's generic entity for its single editable column; an existing
application can use its own Article entity with an accessible `title` field.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/ArticleForm.php [Island]

<<< @/../tests/docs/app/templates/element/Interact/article_form.php [Element]

<<< @/../tests/docs/app/src/Model/Table/ArticlesTable.php [Table]

<<< @/../tests/docs/app/schema/articles.sql [Schema]

```php [Mount]
<?= $this->Interact->render('article-form') ?>
```

:::

## Behavior

An empty title or a title longer than 120 characters fails Table validation.
The failed entity remains available to FormHelper, and `setErrors()` supplies
Interact's field feedback. A valid title inserts one row and dispatches
`article-saved`. See [Events](/guide/events) for a listing refresh listener.

This example creates a public record. Existing-record editors must also check
current resource access before loading or saving a supplied ID. Keep application
authentication and authorization in their normal layers.
