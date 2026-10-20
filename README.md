# CakePHP Interact

Add live search, inline editing, and conditional fields to CakePHP with
server-rendered PHP islands. Keep native templates and application logic in PHP;
update the region that changed through AJAX.

**Development preview: PHP 8.5, CakePHP 6 development builds, modern browsers.**
The API is experimental. Source checkouts require a browser asset build.

An island declares its state and callable actions. Mount its declared alias in
an ordinary CakePHP template:

```php
<?= $this->Interact->render('article-editor', props: ['articleId' => 7], key: 'article-7') ?>
```

Its element binds the draft and submits to the island's explicit `save()` action:

```html
<form data-interact-submit="save">
  <label>Title <input data-interact-model="title" /></label>
  <button type="submit" :disabled="$server.busy">Save article</button>
  <p role="alert" x-text="$server.error"></p>
</form>
```

See the [complete editor example](https://josbeir.github.io/cakephp-interact/guide/forms)
for the cooperating class, authorization, validation, and persistence.

[Documentation](https://josbeir.github.io/cakephp-interact/) ·
[Installation](https://josbeir.github.io/cakephp-interact/installation) ·
[Your first island](https://josbeir.github.io/cakephp-interact/getting-started) ·
[API reference](https://josbeir.github.io/cakephp-interact/api)

## Try the demo

From a repository checkout with PHP 8.5, SQLite support, Composer, and Node 24+:

```sh
composer install
npm ci
npm run build
npm run preview
```

Open `http://127.0.0.1:8765`. Try independent editors, dependent choices,
conditional forms, and a search library of 1,200 resources. The demo uses seeded
SQLite fixtures and a test-only identity. See [the example guide](docs/examples.md).

## Contributing

Read [AGENTS.md](AGENTS.md) and [the contributor guide](docs/contributing.md).
PHP and TypeScript each require at least 95% line coverage, including unexecuted
source. Quality gates include Mago, Rector, Vitest, and Playwright across
Chromium, Firefox, and WebKit.

MIT license.
