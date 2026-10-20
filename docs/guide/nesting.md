---
title: Nested islands and events
description: Keep independent drafts and use events to refresh a parent, sibling, or matching article island after a save.
---

# Nested islands and events

A listing can refresh its results while each article editor keeps its own draft.
The demo at `/` combines an `article-list` parent with keyed `article-editor`
children. [Run it locally](/examples) to try editing two titles independently.

Events also connect islands elsewhere on the page. The examples below use the
same `article-saved` event to refresh a parent, another island, or just the island
displaying the saved article.

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
  <!-- Keyed article editors. -->
</section>
```

The parent re-queries its listing. Its child editors retain their local drafts.
The `.stop` modifier keeps the event from also reaching ancestors that might
listen for the same name, including listeners on `window`.

An event is emitted after a successful update response. Validation failures
suppress success events. Event detail is browser-visible and untrusted; it is
useful for UI coordination, not proof that an authorized operation occurred.

## Refresh another island

Suppose an editor saves an article and a separate listing should refresh. Put
this listener around the existing content in the **receiving island's element**:

```html [Inside the receiving island's element]
<section @article-saved.window="$server.sync()">
  <!-- Existing article listing. -->
</section>
```

The `.window` modifier listens for the event after it bubbles to `window`, so the
receiver does not need to contain the editor. `$server` still belongs to the
receiving island: its own listing refreshes when another island saves.

An ordinary `@article-saved` listener only receives events from its element or
descendants; events do not bubble sideways to siblings. Do not put the listener
outside an island when calling `$server`.

If the editor is nested inside the parent example above, remove that parent's
`.stop` so the event can reach window listeners. The parent can still refresh:

```html [Parent listing that also allows other islands to react]
<section @article-saved="$server.sync()">
  <!-- Keyed article editors. -->
</section>
```

Choose the listener that matches the relationship:

| Listener                | Receives events from                                |
| ----------------------- | --------------------------------------------------- |
| `@article-saved`        | Its element and descendants                         |
| `@article-saved.stop`   | Its element and descendants, then stops propagation |
| `@article-saved.window` | Any island whose event reaches `window`             |

Window listeners can hear their own island's events too. Dispatch save events
from successful save actions, rather than from rendering or synchronization
hooks, to avoid refresh loops.

## Refresh only the matching article

The event includes the saved article ID in `$event.detail.id`. If a receiving
island declares an `articleId` prop, as the [article editor](/guide/forms) does,
it can ignore saves for other records:

```html [Inside an island with an articleId prop]
<section
  @article-saved.window="
    $event.detail.id === $server.state.articleId
      && $server.sync()
  "
>
  <!-- Existing article details. -->
</section>
```

For an event with `['id' => 7]`, only receivers whose `articleId` is `7` refresh.
The ID identifies which display needs updating; the receiving island still
applies its normal server-side authorization.

## What a refresh updates

`$server.sync()` sends the receiving island's current writable drafts through
its normal update lifecycle and renders it again. Queries in `viewData()` run
again, so a listing loaded there sees the saved article. Newer local edits and
retained child-island drafts remain protected during the render.

`mount()` and the hosting controller action do not run again. A title loaded into
editable state only in `mount()` therefore keeps its draft; `sync()` does not
replace it with a fresh database value. Use `viewData()` for current read-only
data. If an application needs to reload editable state too, expose an explicit
action and decide how it should handle unsaved changes.

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
