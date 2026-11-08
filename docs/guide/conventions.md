---
title: Conventions
description: Organize island classes, native templates, shared elements, and plugin overrides.
---

# Conventions

Interact resolves `Island` classes on demand from `src/Island/` and renders matching
native PHP templates from `templates/island/`. No discovery or registration
attribute is required.

## Island classes and templates

Group classes and templates by feature. Namespace folders retain their casing;
the final class name loses its `Island` suffix and is underscored with CakePHP's
`Inflector::underscore()`.

```text
src/Island/Article/
├── EditorIsland.php
├── FormIsland.php
└── ListingIsland.php

templates/island/Article/
├── editor.php
├── form.php
└── listing.php
```

| Render name      | Class                             | Template                    |
| ---------------- | --------------------------------- | --------------------------- |
| `Counter`        | `App\Island\CounterIsland`        | `island/counter.php`        |
| `article_editor` | `App\Island\ArticleEditorIsland`  | `island/article_editor.php` |
| `Article/Editor` | `App\Island\Article\EditorIsland` | `island/Article/editor.php` |
| `Realtime/Chat`  | `App\Island\Realtime\ChatIsland`  | `island/Realtime/chat.php`  |

Template paths in the table are relative to `templates/`. The [demo](/demo)
uses matching article, composition, realtime, streaming, and debugging folders.
Application namespaces are read from CakePHP's
`App.namespace` configuration.

```php
<?= $this->Interact->render('Article/Editor') ?>
```

Names are normalized to class-style casing, so `Article/Editor` and `article/editor`
select the same class. Underscores and hyphens within a segment are camelized.
A slash selects a nested namespace; a dot separates a plugin name from its island.
Traversal segments and arbitrary filesystem paths are rejected.
Moving or renaming a class changes its conventional render name and inferred template.

## Generate an island with Bake

`bin/cake bake island` creates an empty island class and its matching PHP template.
Complete [installation](/installation), then install CakePHP's optional Bake plugin
as a development dependency if your application does not already have it:

```sh
composer require --dev cakephp/bake:^3.0
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

### Pagination declaration

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

### Plugin targets and existing files

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

## Class references

Mount calls also accept class references for IDE completion, navigation, and rename
support:

```php
<?php
use App\Island\Article\EditorIsland;
?>
<?= $this->Interact->render(EditorIsland::class) ?>
```

This is equivalent to `render('Article/Editor')`: both use the same template,
canonical island name, and default child key. Props, explicit keys, and loading
options work with either form. References to classes from loaded plugins, such as
`Commerce\Island\Article\EditorIsland::class`, retain CakePHP's normal application
template overrides.

Automatic resolution requires the configured application or loaded plugin's
`Island` namespace and the `Island` class suffix. Classes in other locations need
[explicit registration](#application-subclasses-and-explicit-names) on every
request before mounting by class or name. Browser updates use signed island names,
never PHP class references.

## Explicit rendering

Use optional `Interact\Attribute\Render` to override the view or root HTML tag:

```php
#[Render(template: 'Articles/edit', tag: 'section')]
```

```php
#[Render(element: 'Article/editor')]
```

Omitting both targets retains the inferred template, including when only `tag:`
is set. Declaring both targets is invalid. A missing view uses CakePHP's native
missing-template error. See [render options](/guide/islands#render-options).

Props, state, errors, and `viewData()` provide the same template variables on
initial mounts and updates. Controllers' view variables are not automatically
transported.

## Plugin templates and overrides

Use CakePHP plugin syntax to select an island from a loaded plugin:

```php
<?= $this->Interact->render('Commerce.Article/Editor') ?>
```

This resolves `Commerce\Island\Article\EditorIsland` and its default view:

```text
plugins/Commerce/templates/island/Article/editor.php
```

The application overrides that view by adding:

```text
templates/plugin/Commerce/island/Article/editor.php
```

Keep using the same render name. CakePHP selects the override for initial mounts,
updates, and deferred initialization. The original island class continues handling
state, actions, and authorization. Themes retain CakePHP's normal precedence.

Unqualified `Render` targets are relative to the island's owner, independently of
the hosting controller and update route. An explicit plugin reference selects
another loaded plugin's view:

```php
#[Render(template: 'Commerce.island/Article/editor')]
```

Vendor plugin names retain their slash. For `Acme/Commerce.Article/Editor`, the
application override is
`templates/plugin/Acme/Commerce/island/Article/editor.php`.
Relative elements inside a view use that view's selected plugin context.

## Shared elements and page islands

Use island templates as entry views. Keep markup shared between islands and
ordinary pages in `templates/element/`, grouped by application feature.
The demo's ordinary and polling article editors both compose:

```php
<?= $this->element('Article/editor') ?>
```

Custom loading placeholders are elements too. The demo groups them under
`templates/element/Loading/`; mount calls select them with `placeholder:`.
See [lazy loading](/guide/lazy-loading).

A [page island](/guide/page-islands) can use `#[Render(template: 'Articles/page')]`
to reuse an action template. That template is already the island's content and
must not mount the same island recursively.

## Application subclasses and explicit names

An application subclass gets its own class-derived name and default template.
It does not replace a plugin-qualified name. For example, an application
`Article/Editor` and `Commerce.Article/Editor` select separate classes. Use
`#[Render(template: 'Commerce.island/Article/editor')]` to reuse the plugin's view.

For nonstandard class locations or custom public names, explicitly register the
class through `Discovery\Registry::register($class, $name)`. Classes outside an
`Island` namespace must select an explicit template or element. Different classes
cannot register the same normalized name. Ordinary conventional islands need no
registration.

Domain queries, validation, and persistence stay in their normal CakePHP layers.
Island classes orchestrate application behavior; directory organization does not
transport services or queries into browser state.
