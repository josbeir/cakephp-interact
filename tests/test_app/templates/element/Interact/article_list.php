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
    <div class="card-heading"><div><p class="eyebrow">01 / Nested islands</p><h2>An editor in every row</h2></div><span class="capability">Search + save</span></div>
    <p class="card-description">Each article is an independent island. Search updates this list; saving updates just the article, then lets the list know.</p>
    <?= $this->Form->create(null, ['url' => false, 'idPrefix' => 'article-list', '@submit.prevent' => '$server.sync()']) ?>
        <?= $this->Form->control('search', [
            'type' => 'search',
            'label' => 'Search articles',
            'value' => $search,
            'placeholder' => 'Try CakePHP or Alpine…',
            'data-interact-model' => 'search',
            'data-interact-update' => 'input',
            'data-interact-debounce' => 250,
        ]) ?>
    <?= $this->Form->end() ?>
    <div class="list-toolbar"><div class="page-count"><p class="quiet">Page <?= (string)$page ?></p><span class="quiet">of <?= (string)$totalPages ?></span></div><span class="quiet" x-show="$server.busy" x-cloak>Updating…</span>
        <div x-data="{ open: false }">
            <button type="button" class="button-text" @click="open = !open" :aria-expanded="open">Toggle help</button>
            <p class="help-note" x-show="open" x-cloak>Search and edit without leaving the page.</p>
        </div>
    </div>
    <div class="article-rows">
    <?php foreach ($articles as $article): ?>
        <?= $this->Interact->render('article-editor', props: ['articleId' => (int)$article->get('id')], key: 'article-' . (string)$article->get('id')) ?>
    <?php endforeach; ?>
    </div>
    <?php if ($articles->isEmpty()): ?>
        <p class="empty-state">No articles here. Try another search or go back a page.</p>
    <?php endif; ?>
    <div class="pagination"><button type="button" data-interact-click="previous" :disabled="$server.busy || <?= $page <= 1 ? 'true' : 'false' ?>">Previous page</button><button type="button" data-interact-click="next" :disabled="$server.busy || <?= $page >= $totalPages ? 'true' : 'false' ?>">Next page <span aria-hidden="true">→</span></button></div>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
