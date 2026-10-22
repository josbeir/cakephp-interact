<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * templates/island/resource_search.php
 *
 * @var \Interact\View\TemplateView $this
 * @var string $search
 * @var int $page
 * @var int $pages
 * @var int $total
 * @var iterable<\Cake\Datasource\EntityInterface> $resources
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
<p role="status"><?= $total ?> results. Page <?= $page ?> of <?= $pages ?>.</p>
<ul>
    <?php foreach ($resources as $resource): ?>
        <li><?=(string)h($resource->get('title')) ?></li>
    <?php endforeach; ?>
</ul>
<button
    type="button"
    @click="$server.call('goTo', <?= $page - 1 ?>)"
    <?= $page <= 1 ? 'disabled' : '' ?>
>
    Previous
</button>
<button
    type="button"
    @click="$server.call('goTo', <?= $page + 1 ?>)"
    <?= $page >= $pages ? 'disabled' : '' ?>
>
    Next
</button>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
