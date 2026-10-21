---
title: Redirects
description: Navigate to a CakePHP route or external URL after an island action.
---

# Redirects

`redirect()` navigates the browser after a successful island action.
Pass a CakePHP route array, URL string, or PSR URI:

```php
$this->redirect(['_name' => 'articles:view', 'id' => $article->id]);
```

The action is called normally:

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

See the complete [save-and-redirect example](/examples/redirect).

## Route arrays

Use the same destination formats as CakePHP routing:

```php
$this->redirect(['controller' => 'Articles', 'action' => 'view', $article->id]);
$this->redirect(['_name' => 'articles:view', 'id' => $article->id]);
$this->redirect(['action' => 'index']);
```

Partial arrays use the controller, action, plugin, and prefix of the page that
mounted the island. They do not inherit the Interact update endpoint's defaults.
These defaults are signed with the island and preserved for later child mounts.
Explicit route arguments override them.

Query parameters and fragments use CakePHP's normal `?` and `#` keys. Application
base paths are handled by CakePHP routing.

## External URLs

The same method supports external HTTP(S) URLs:

```php
$this->redirect('https://payments.example/checkout');
```

Validate user-supplied destinations before passing them to `redirect()`.
Interact rejects executable schemes, credentials, control characters, and ambiguous
backslashes; it does not maintain an application allowlist of external hosts.

## Browser history

A redirect adds the destination to browser history. Use `replace: true` when
Back should skip the current page:

```php
$this->redirect(['action' => 'index'], replace: true);
```

Redirects perform full-page browser navigation, including a fresh page load for
same-page fragment targets. They do not enable enhanced navigation or replace
CakePHP routes.

## Action lifecycle

`redirect()` is available only inside declared `#[Action]` methods. It records
one outcome for the response and does not stop PHP execution:

```php
$this->redirect(['action' => 'index']);
return; // Stop this method if more application code follows.
```

After the action succeeds, Interact skips `viewData()`, template rendering,
snapshot generation, URL-state publication, and queued application events. This
supports delete-and-redirect actions whose deleted record can no longer render.
The initiating island stops polling and drops unsent requests before leaving.

Validation errors suppress the redirect and produce a normal validation render.
An action may request one redirect or one download, not both or repeated outcomes.
Calls from `authorize()`, `mount()`, `updated()`, or rendering hooks are rejected.
Initial page redirects belong in the CakePHP controller.

The helper produces an explicit JSON navigation result. Unexpected HTTP redirects
from authentication or other middleware retain normal [failure handling](/guide/failures).
A failed response never automatically repeats the action.
