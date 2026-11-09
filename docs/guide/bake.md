---
title: Bake
description: Generate empty island classes and matching templates with CakePHP Bake, optionally declaring pagination.
---

# Bake

`bin/cake bake island` creates an empty island class and its matching PHP template.
Complete [installation](/installation), then install CakePHP's optional Bake plugin
as a development dependency if your application does not already have it:

```sh
composer require --dev cakephp/bake:^3.8
```

Load Bake for console development alongside Interact. For example, add the import
at the top of `src/Application.php` and the call inside `bootstrap()` after
`parent::bootstrap()`:

```php
use Bake\BakePlugin;

// Inside Application::bootstrap():
if (PHP_SAPI === 'cli' && class_exists(BakePlugin::class)) {
    $this->addPlugin(BakePlugin::class);
}
```

Keep an existing Bake registration if your application already loads it. Bake is
not required by the deployed Interact runtime.

Supply the name directly, or omit it for interactive setup:

```sh
bin/cake bake island Counter
bin/cake bake island
```

```text
Island name (for example Counter or Admin/Article/Listing):
> Admin/Article/Listing

Enable pagination? (y/n) [n]:
> n
```

For `Admin/Article/Listing`, Bake creates:

```text
src/Island/Admin/Article/ListingIsland.php
templates/island/Admin/Article/listing.php
```

The class uses `App\Island\Admin\Article` or your configured application namespace.
Namespace groups can be nested to any depth. The template contains a placeholder
comment; add your own markup, declared state, and actions. Bake prints the mount call:

```php
<?= $this->Interact->render('Admin/Article/Listing') ?>
```

## Pagination declaration

```sh
bin/cake bake island Article/Listing --pagination
```

`--pagination` adds a `#[Pagination]` declaration on public `PaginationState $paging`
and the corresponding template annotation. It prepares the declaration only: add
your own query and controls using the [pagination guide](/guide/pagination).
Generation never connects to the database.

With a direct name, pagination defaults to off and no setup questions are asked.
With no name, the command asks whether to enable pagination, defaulting to no.
An explicit `--pagination` skips that question. Empty input cancels generation;
invalid interactive names can be corrected at the prompt.

## Plugin targets and existing files

Use a loaded plugin's name to generate into its native class and template directories:

```sh
bin/cake bake island Commerce.Article/Editor
bin/cake bake island Article/Editor --plugin Commerce
```

Both create `Commerce\Island\Article\EditorIsland` and
`templates/island/Article/editor.php` within Commerce. Vendor plugin names such as
`Acme/Commerce.Article/Editor` work too. If both naming forms are supplied, their
plugin targets must agree.

Existing identical files are left unchanged. Bake prompts before overwriting changed
files; `--force` permits overwriting without asking. Skipped or unwritable files
produce an incomplete-generation error and a nonzero exit. Review each file's output
before using a partially generated scaffold. Quiet mode (`--quiet`) never prompts
and requires an explicit name.

Use `--help` for command examples. Native Bake themes and `--theme` apply to the
generator. Applications can override its templates at:

```text
templates/plugin/Interact/bake/Island/island.twig
templates/plugin/Interact/bake/Island/template.twig
```
