---
title: 'Nested islands'
description: Render independent child islands with stable keys and preserved drafts.
---

<span id="nested-islands-and-events"></span>

# Nested islands

Nested islands keep their own state, requests, and input drafts when a parent
island refreshes.

<span id="give-each-editor-an-identity"></span>

## Rendering children

For a listing whose `viewData()` supplies `$articles`:

```php
<?php foreach ($articles as $article): ?>
    <?= $this->Interact->render(
        'Article/Editor',
        props: ['articleId' => (int)$article->id],
        key: 'article-' . (string)$article->id,
    ) ?>
<?php endforeach; ?>
```

The child declares `articleId` with `#[Prop]`. `Article/Editor` resolves to
`App\Island\Article\EditorIsland` on demand.

## Keys

Keys must be unique among siblings and stable between renders. Use record IDs,
rather than positions in a result list. A matching child keeps its draft and
browser instance when the parent refreshes.

Changing a child's alias or props requires a new key. Include a revision when
you intentionally want a fresh mount. Removing a child disposes it; showing it
again creates a new instance.

<span id="announce-a-successful-save"></span>
<span id="refresh-another-island"></span>
<span id="refresh-only-the-matching-article"></span>
<span id="what-a-refresh-updates"></span>

## Events

Use [events](/guide/events) to refresh a parent or sibling after a child saves.
`$server` always resolves to the nearest island, including inside nested markup.

<span id="keep-browser-only-state-local"></span>

## Local Alpine state

Local `x-data` survives ordinary updates while its element is preserved. Use it
for open panels and other browser-only UI preferences. Store PHP-interactive
values in declared properties instead.
