<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $search
 * @var int $page
 * @var iterable<\Cake\Datasource\EntityInterface> $articles
 */
?>
<section aria-label="Articles" @article-saved.stop="$server.sync()">
    <h2>Articles</h2>
    <label>
        Search articles
        <input type="search" value="<?= (string)h($search) ?>" data-interact-model="search" data-interact-update="input" data-interact-debounce="250">
    </label>
    <p>Page <?= (string)h((string)$page) ?></p>
    <div x-data="{ open: false }">
        <button type="button" @click="open = !open">Toggle help</button>
        <p x-show="open">Search and edit without leaving the page.</p>
    </div>
    <?php foreach ($articles as $article): ?>
        <?= $this->Interact->render('article-editor', props: ['articleId' => (int)$article->get('id')], key: 'article-' . (string)$article->get('id')) ?>
    <?php endforeach; ?>
    <button type="button" data-interact-click="previous">Previous page</button>
    <button type="button" data-interact-click="next">Next page</button>
    <p role="alert" x-text="$server.error"></p>
</section>
