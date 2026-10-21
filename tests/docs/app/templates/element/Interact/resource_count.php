<?php
declare(strict_types=1);

/**
 * templates/element/Interact/resource_count.php
 *
 * @var \Interact\View\TemplateView $this
 * @var int $total
 */
?>
<section aria-label="Resource count">
    <p><?= $total ?> matching resources</p>
    <?= $this->Form->button('Refresh count', [
        'type' => 'button',
        '@click' => '$server.sync()',
        ':disabled' => '$server.busy',
    ]) ?>
</section>
