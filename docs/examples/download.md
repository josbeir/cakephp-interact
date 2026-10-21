---
title: Invoice download
description: Update an island and download a sample CSV through a normal CakePHP endpoint.
---

# Invoice download

This example updates a request count and downloads a public sample invoice without
leaving the page. The file is served by an ordinary CakePHP controller.

Use the [installation setup](/installation) and normal application attribute
routing. Save each file at the path in its docblock. The sample is public;
real invoice endpoints must authorize access to the requested record independently.

::: code-group

<<< @/../tests/docs/app/src/Island/InvoiceDownload.php [Island]

<<< @/../tests/docs/app/templates/element/Interact/invoice_download.php [Element]

<<< @/../tests/docs/app/src/Controller/InvoicesController.php [Controller]

```php [Mount]
<?= $this->Interact->render('invoice-download') ?>
```

:::

The browser requests `/invoices/sample` after the island renders the new count.
CakePHP's `withDownload()` supplies the filename. For a protected example with
current-permission checks, open `/outcomes` in the larger demo.

See [downloads](/guide/downloads) for destination rules, file responses, and the
limits of transfer feedback.
