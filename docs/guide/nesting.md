---
title: Nested islands and events
description: Mount independent editors with stable keys and refresh their parent after a successful save.
---

# Nested islands and events

A listing can refresh its results while each article editor keeps its own draft.
The demo at `/` combines an `article-list` parent with keyed `article-editor`
children. [Run it locally](/examples) to try editing two titles independently.

## Give each editor an identity

Inside the parent island's element, render children with their record ID as an
explicit prop and part of a stable key:

```php [Inside the parent listing element]
<?php foreach ($articles as $article): ?>
    <?= $this->Interact->render(
        'article-editor',
        props: ['articleId' => (int)$article->id],
        key: 'article-' . (string)$article->id,
    ) ?>
<?php endforeach; ?>
```

`$articles` comes from the parent's `viewData()` query, and `article-editor` is the
alias declared by the [editor class](/guide/forms). Do not use a changing array
position as the key. Matching child islands keep their existing state and drafts
through a parent refresh.

Keys must be unique among siblings. Changing a child's alias or props requires a
new key. Include a revision in the key when you intentionally want a fresh mount.
A child removed by filtering is disposed; showing it again mounts a new instance.

## Announce a successful save

After the editor saves through the application's normal model code, it queues a
browser event:

```php [Inside ArticleEditor::save(), after a successful save]
$this->dispatch('article-saved', ['id' => $this->articleId]);
```

Listen inside the parent island and request a refresh:

```html [Inside the parent listing element]
<section @article-saved.stop="$server.sync()">
  <!-- Render the keyed editors here. -->
</section>
```

The parent re-queries its listing. Its child editors retain their local drafts.
The `.stop` modifier keeps the event from also reaching ancestors that might
listen for the same name.

An event is emitted after a successful update response. Validation failures
suppress success events. Event detail is browser-visible and untrusted; it is
useful for UI coordination, not proof that an authorized operation occurred.

## Keep browser-only state local

Use Alpine for a disclosure that needs no PHP request:

```html
<div x-data="{ expanded: false }">
  <button type="button" @click="expanded = !expanded" :aria-expanded="expanded">
    Details
  </button>
  <div x-show="expanded">Additional help for this editor.</div>
</div>
```

Local Alpine state survives island morphs when the owning DOM element is retained.
For dynamic rows, use stable IDs and keep each control in its owning island.
The task board at `/playground` demonstrates adding, toggling, and removing rows.
