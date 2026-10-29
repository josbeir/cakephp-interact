<?php
declare(strict_types=1);

use Cake\Datasource\EntityInterface;
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var \Interact\Pagination\PaginationState $resourcesPaging
 * @var \Interact\Pagination\PaginationState $articlesPaging
 * @var \Interact\Pagination\Paginated $resources
 * @var \Interact\Pagination\Paginated $articles
 * @var string $notes
 */
/** @var iterable<EntityInterface> $resourceRows */
$resourceRows = $resources->items();
/** @var iterable<EntityInterface> $articleRows */
$articleRows = $articles->items();
?>
<div class="demo-card">
    <?= $this->Form->control('notes', ['label' => 'Overview notes', 'value' => $notes, 'x-interact:model' => '']) ?>
    <section aria-label="Overview resources">
        <?php $this->Paginator->setPaginated($resources); ?>
        <h2>Resources</h2>
        <p role="status"><?= $this->Paginator->counter('Resources {{start}}–{{end}} of {{count}}') ?></p>
        <?= $this->Form->control('resourceSize', [
            'label' => 'Resources per page',
            'options' => [10 => '10', 20 => '20', 50 => '50'],
            'value' => $resourcesPaging->limit,
            'x-interact:model.live' => 'resourcesPaging.limit',
        ]) ?>
        <?= $this->Paginator->sort('title', 'Sort resources') ?>
        <ul>
            <?php foreach ($resourceRows as $resource): ?>
                <li><?= (string)h((string)$resource->get('title')) ?></li>
            <?php endforeach; ?>
        </ul>
        <nav aria-label="Resource pages">
            <?= $this->Paginator->prev('Previous resources') ?>
            <?= $this->Paginator->next('Next resources') ?>
        </nav>
    </section>
    <section aria-label="Overview articles">
        <?php $this->Paginator->setPaginated($articles); ?>
        <h2>Articles</h2>
        <p role="status"><?= $this->Paginator->counter('Articles {{start}}–{{end}} of {{count}}') ?></p>
        <?= $this->Paginator->sort('title', 'Sort articles') ?>
        <ul>
            <?php foreach ($articleRows as $article): ?>
                <li><?= (string)h((string)$article->get('title')) ?></li>
            <?php endforeach; ?>
        </ul>
        <nav aria-label="Article pages">
            <?= $this->Paginator->prev('Previous articles') ?>
            <?= $this->Paginator->next('Next articles') ?>
        </nav>
    </section>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</div>
