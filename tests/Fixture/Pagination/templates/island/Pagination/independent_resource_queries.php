<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var \Interact\Pagination\Paginated $first
 * @var \Interact\Pagination\Paginated $second
 */
?>
<?php $this->Paginator->setPaginated($first); ?>
<nav aria-label="First pages">
    <?= $this->Paginator->counter('First {{start}}–{{end}} of {{count}}') ?>
    <?= $this->Paginator->next('Next first') ?>
</nav>
<?php $this->Paginator->setPaginated($second); ?>
<nav aria-label="Second pages">
    <?= $this->Paginator->counter('Second {{start}}–{{end}} of {{count}}') ?>
    <?= $this->Paginator->next('Next second') ?>
</nav>
