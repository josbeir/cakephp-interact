<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * templates/island/resource_search.php
 *
 * @var \Interact\View\TemplateView $this
 * @var string $search
 * @var \Cake\Datasource\Paging\PaginatedInterface<int, \Cake\Datasource\EntityInterface> $resources
 */
?>
<?= $this->Form->create(null, ['url' => false, 'x-interact:submit' => '$server.sync()']) ?>
    <?= $this->Form->control('search', [
        'type' => 'search',
        'label' => 'Search resources',
        'value' => $search,
        'maxlength' => 100,
        'x-interact:model.live.debounce.300ms' => 'search',
    ]) ?>
<?= $this->Form->end() ?>
<p role="status"><?= $this->Paginator->counter('{{count}} results. Page {{page}} of {{pages}}.') ?></p>
<ul>
    <?php foreach ($resources as $resource): ?>
        <li><?=(string)h($resource->get('title')) ?></li>
    <?php endforeach; ?>
</ul>
<nav aria-label="Resource pages">
    <ul>
        <?= $this->Paginator->prev('Previous') ?>
        <?= $this->Paginator->numbers() ?>
        <?= $this->Paginator->next('Next') ?>
    </ul>
</nav>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
