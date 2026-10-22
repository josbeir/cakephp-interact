<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * templates/element/Loading/resource_count.php
 *
 * @var \Interact\View\TemplateView $this
 * @var string $search
 */
?>
<section aria-label="Resource count placeholder">
    <p>Count resources matching “<?= (string)h($search) ?>”.</p>
    <?= $this->Form->button('Load count', [
        'type' => 'button',
        '@click' => '$server.sync()',
        ':disabled' => '$server.busy',
    ]) ?>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
