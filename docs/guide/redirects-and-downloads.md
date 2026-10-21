---
title: Redirects and downloads
description: Navigate to another page or request a native file download after an island action.
---

# Redirects and downloads

Use `redirect()` to navigate after an island action, or `downloadUrl()` to download
a file while keeping the page open. Both helpers accept CakePHP route arrays,
URL strings, and PSR URIs.

## Redirects

Call `redirect()` inside a declared `#[Action]`:

```php
$this->redirect(['_name' => 'articles:view', 'id' => $article->id]);
```

Call the action normally from the template:

::: code-group

```html [HTML]
<button type="button" @click="$server.call('save')">Save article</button>
```

```php [FormHelper]
<?= $this->Form->button('Save article', [
    'type' => 'button',
    '@click' => '$server.call("save")',
]) ?>
```

:::

After the action succeeds, Interact skips `viewData()`, template rendering,
snapshot generation, URL-state publication, and queued application events. This
supports delete-and-redirect actions whose deleted record can no longer render.
The initiating island stops polling and drops unsent requests before leaving.

See the complete [save-and-redirect example](/examples/redirect).

### Browser history

A redirect adds the destination to browser history. Use `replace: true` when
Back should skip the current page:

```php
$this->redirect(['action' => 'index'], replace: true);
```

Redirects perform full-page browser navigation, including a fresh page load for
same-page fragment targets. They do not enable enhanced navigation or replace
CakePHP routes.

## Downloads

Call `downloadUrl()` with a same-origin application endpoint:

```php
$this->downloadUrl(['_name' => 'invoices:download']);
```

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

After an accepted render, Interact applies state and URL changes, requests the
download, and then dispatches application events. The page stays open.
See the complete [invoice download example](/examples/download).

### Destination endpoint

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

The browser fetches the file through a separate native GET request. Interact does
not buffer it in JavaScript, encode it in the update payload, or introduce
temporary file storage. Supply a route that serves the attachment, rather than
a file path, file contents, or an external storage URL.

### Transfer feedback

`$server.pending()` and `$server.loading()` describe the island action, not the
file transfer. A successful action result confirms that the download was requested;
it does not confirm that the user saved the file.

The browser manages progress, cancellation, destination HTTP failures, and saving.
Those outcomes are not reported through `$server.error`. Downloads follow browser
settings and restrictions.

## Route arrays

Both helpers use the same destination formats as CakePHP routing:

```php
$this->redirect(['controller' => 'Articles', 'action' => 'view', $article->id]);
$this->redirect(['_name' => 'articles:view', 'id' => $article->id]);
$this->downloadUrl(['action' => 'export']);
```

Partial arrays use the controller, action, plugin, and prefix of the page that
mounted the island. They do not inherit the Interact update endpoint's defaults.
These defaults are signed with the island and preserved for later child mounts.
Explicit route arguments override them.

Query parameters and fragments use CakePHP's normal `?` and `#` keys. Application
base paths are handled by CakePHP routing.

### External URLs

Redirects support external HTTP(S) URLs:

```php
$this->redirect('https://payments.example/checkout');
```

Downloads must remain on the page's origin, including its scheme and port.
Both helpers reject executable schemes, credentials, control characters, and
ambiguous backslashes.

Validate user-supplied redirect destinations before passing them to `redirect()`.
Interact does not maintain an application allowlist of external hosts.

## Action lifecycle

Both helpers are available only inside declared `#[Action]` methods. Calls from
`authorize()`, `mount()`, `updated()`, or rendering hooks are rejected. Passive
synchronization and history restoration cannot request either outcome.
Initial page redirects belong in the CakePHP controller.

The helpers record one outcome for the response and do not stop PHP execution:

```php
$this->redirect(['action' => 'index']);
return; // Stop this method if more application code follows.
```

An action may request one redirect or one download, not both or repeated outcomes.
Validation errors suppress either outcome and produce a normal validation render.

Redirects use an explicit JSON navigation result. Unexpected HTTP redirects from
authentication or other middleware retain normal [failure handling](/guide/failures).
Failed island requests never automatically repeat actions or file transfers.
