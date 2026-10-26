<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $controlPrefix
 * @var string|null $query
 * @var array<string, mixed> $filters
 */
?>
<section role="region" aria-label="<?= (string)h(ucfirst($controlPrefix)) ?> filters" class="card">
    <h2><?= (string)h(ucfirst($controlPrefix)) ?> filters</h2>
    <?= $this->Form->create(null, ['x-interact:model' => 'filters', 'x-interact:submit' => '$server.call("apply")']) ?>
        <?= $this->Form->control('query', [
            'id' => $controlPrefix . '-query',
            'label' => 'Query',
            'value' => $query,
            'x-interact:model' => 'query',
        ]) ?>
        <fieldset>
            <legend>Nested filters</legend>
            <?= $this->Form->control('status', [
                'id' => $controlPrefix . '-status',
                'label' => 'Status',
                'value' => $filters['status'] ?? '',
            ]) ?>
            <?= $this->Form->control('price.min', [
                'id' => $controlPrefix . '-min',
                'label' => 'Minimum price',
                'value' => $filters['price']['min'] ?? '',
                'x-interact:model' => 'filters.price.min',
            ]) ?>
        </fieldset>
        <?= $this->Form->control('notes', [
            'id' => $controlPrefix . '-notes',
            'label' => 'Unsent notes',
            'type' => 'textarea',
            'x-interact:model' => 'notes',
        ]) ?>
        <?= $this->Form->button('Apply filters') ?>
    <?= $this->Form->end() ?>
    <?= $this->Form->control('maximumPrice', [
        'id' => $controlPrefix . '-max',
        'label' => 'Maximum price',
        'value' => $filters['price']['max'] ?? '',
        'x-interact:model.change' => 'filters.price.max',
    ]) ?>
    <div class="button-row">
        <button type="button" @click="$server.call('selectNull')">Select null</button>
        <button type="button" @click="$server.call('clearTags')">Clear tags</button>
    </div>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
    <p>Query: <output aria-label="Query value" x-text="$server.state.query === null ? 'null' : $server.state.query"></output></p>
    <p>Actions: <output aria-label="Action count" x-text="$server.state.calls"></output></p>
    <pre aria-label="Accepted filters"><?= (string)h(json_encode($filters, JSON_THROW_ON_ERROR)) ?></pre>
</section>
