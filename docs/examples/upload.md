---
title: 'Single document upload'
description: Stage one file and save it through a normal CakePHP entity and Table.
---

# Single document upload

This form stages one PDF or text document, validates it, and saves a record and
a private file.

Complete [installation](/installation) first. Copy the files below to the indicated
application locations.

Create the `documents` table using the schema tab and a writable private
`TMP . 'example-documents/'` directory (usually `tmp/example-documents/`). This
sample output directory is separate from Interact's staging directory. Choose
your application's persistent storage location for production.

`document` is a transient validation field, not a database column. The example
uses a generic entity; an existing Document entity must allow the supplied fields.

## Files

::: code-group

<<< @/../tests/docs/app/src/Island/DocumentUploadIsland.php [Island]

<<< @/../tests/docs/app/templates/island/document_upload.php [Template]

<<< @/../tests/docs/app/src/Model/Table/DocumentsTable.php [Table]

<<< @/../tests/docs/app/schema/documents.sql [Schema]

```php [Mount]
<?= $this->Interact->render('DocumentUpload') ?>
```

:::

## Behavior

Selection stages the file. `save()` validates through `DocumentsTable`, keeps the
failed entity for FormHelper, and moves the accepted file only after a successful
insert. Validation failures retain the staged file, so submitting again does not
transfer it again. Clearing the property releases its staging reference.

The record is inserted before the move. A storage failure can therefore leave a
row behind. Coordinate rollback and cleanup through your application's storage
service or transaction boundary when required; the filesystem is not transactional.
See [File uploads](/guide/uploads) for limits, multiple files, and cleanup.
