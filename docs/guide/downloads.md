---
title: Downloads
description: Request a native file download from an application endpoint after an island action.
---

# Downloads

`downloadUrl()` asks the browser to download a file from a same-origin application
endpoint after an island action:

```php
$this->downloadUrl(['_name' => 'invoices:download']);
```

Call the action normally:

::: code-group

```html [HTML]
<button type="button" @click="$server.call('download')">
  Download invoice
</button>
```

```php [FormHelper]
<?= $this->Form->button('Download invoice', [
    'type' => 'button',
    '@click' => '$server.call("download")',
]) ?>
```

:::

The island updates normally and the page stays open. See the complete
[invoice download example](/examples/download).

## Destination endpoint

The helper accepts a CakePHP route array, URL string, or PSR URI. It uses the same
[page routing defaults](/guide/redirects#route-arrays) as `redirect()`.
Downloads must remain on the page's origin, including its scheme and port.

Serve the attachment using CakePHP's ordinary response facilities:

```php
return $this->getResponse()
    ->withType('text/csv')
    ->withDownload('invoice.csv')
    ->withStringBody($csv);
```

For existing files, use `withFile($path, ['download' => true, 'name' => 'invoice.pdf'])`.
The endpoint owns the filename, content type, streaming, and response headers.
It must independently authenticate the request and authorize access to protected files.
An island response grants no access to the destination.

## Action lifecycle

Use `downloadUrl()` only inside a declared `#[Action]`. The helper records a
request and does not stop PHP execution. An action may request one download or
one redirect, not both or repeated outcomes.

After an accepted render, Interact applies state and URL changes, requests the
download, and then dispatches application events. Validation errors suppress
the download. Passive synchronization and history restoration cannot request one.

The file is fetched by the browser through a separate native GET request.
Interact does not buffer it in JavaScript, encode it in the update payload,
or introduce temporary file storage. Direct paths, file contents, and external
storage URLs are not accepted as file inputs by this helper; supply an application
route that serves the attachment.

## Transfer feedback

`$server.pending()` and `$server.loading()` describe the island action, not the
file transfer. A successful action result confirms that the download was requested;
it does not confirm that the user saved the file.

The browser manages progress, cancellation, destination HTTP failures, and saving.
Those outcomes are not reported through `$server.error`. Downloads follow browser
settings and restrictions. Failed island requests never automatically repeat exports
or file transfers.
