<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface<int, \Cake\Datasource\EntityInterface> $rows
 * @var \Interact\Pagination\PaginationState $paging
 */
?>
<p>Accepted page <?= $paging->page ?>. <?= $this->Paginator->counter('{{start}}–{{end}} of {{count}}') ?></p>
<?php if ($paging->sort !== '' || $rows->pagingParam('alias') !== null): ?>
    <?= $this->Paginator->sort('title', 'Title') ?>
<?php endif; ?>
<ul>
    <?php foreach ($rows as $row): ?>
        <li><?= (string)h($row->get('title')) ?></li>
    <?php endforeach; ?>
</ul>
<nav aria-label="Pages">
    <?= $this->Paginator->prev('Previous') ?>
    <?= $this->Paginator->numbers(['modulus' => 3]) ?>
    <?= $this->Paginator->next('Next') ?>
    <?= $this->Paginator->first('First') ?>
    <?= $this->Paginator->last('Last') ?>
</nav>
