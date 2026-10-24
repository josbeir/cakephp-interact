<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var string $search
 * @var int $page
 * @var int $totalPages
 * @var \Cake\ORM\ResultSet<int, \Cake\ORM\Entity> $articles
 */
?>
<section class="demo-card article-list" aria-label="Articles" @article-saved.stop="$server.sync()">
    <div class="card-heading"><div><p class="eyebrow">01 / Nested islands</p><h2>Article listing</h2></div><span class="capability">Search + save</span></div>
    <p class="card-description">Each row is a keyed child island. Search renders the parent; saving a child dispatches an event that refreshes this list.</p>
    <?= $this->Form->create(null, ['url' => false, 'idPrefix' => 'Article/Listing', 'x-interact:submit' => '$server.sync()']) ?>
        <?= $this->Form->control('search', [
            'type' => 'search',
            'label' => 'Search articles',
            'value' => $search,
            'placeholder' => 'Try CakePHP or Alpine…',
            'x-interact:model.live' => 'search',
        ]) ?>
    <?= $this->Form->end() ?>
    <div class="list-toolbar"><div class="page-count"><p class="quiet">Page <?= (string)$page ?></p><span class="quiet">of <?= (string)$totalPages ?></span></div><span class="quiet" x-show="$server.loading(null)" x-cloak>Updating…</span>
        <div x-data="{ open: false }">
            <button type="button" class="button-text" @click="open = !open" :aria-expanded="open">Toggle help</button>
            <p class="help-note" x-show="open" x-cloak>Search and edit without leaving the page.</p>
        </div>
    </div>
    <div class="article-rows">
    <?php foreach ($articles as $article): ?>
        <?= $this->Interact->render('Article/Editor', props: ['articleId' => (int)$article->get('id')], key: 'article-' . (string)$article->get('id')) ?>
    <?php endforeach; ?>
    </div>
    <?php if ($articles->isEmpty()): ?>
        <p class="empty-state">No articles here. Try another search or go back a page.</p>
    <?php endif; ?>
    <div class="pagination"><button type="button" @click="$server.call('previous')" :disabled="$server.busy || <?= $page <= 1 ? 'true' : 'false' ?>">Previous page</button><button type="button" @click="$server.call('next')" :disabled="$server.busy || <?= $page >= $totalPages ? 'true' : 'false' ?>">Next page <span aria-hidden="true">→</span></button></div>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
