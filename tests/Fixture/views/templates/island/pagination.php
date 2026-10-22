<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\Paging\PaginatedInterface<int, \Cake\Datasource\EntityInterface> $rows
 * @var string|null $sort
 * @var int $page
 */
?>
<p>Accepted page <?= $page ?>. <?= $this->Paginator->counter('{{start}}–{{end}} of {{count}}') ?></p>
<?php if (isset($sort)): ?>
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
