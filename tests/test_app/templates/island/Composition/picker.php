<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $search
 * @var string $notes
 * @var string $category
 * @var \Cake\Datasource\ResultSetInterface<array-key, \Cake\Datasource\EntityInterface> $resources
 */
?>
<section class="demo-card" aria-label="Resource picker">
    <div class="card-heading">
        <div>
            <p class="eyebrow">02 · Modelable selection</p>
            <h2>Resource picker</h2>
        </div>
        <span class="topic-tag" data-category><?= (string)h($category) ?></span>
    </div>
    <?= $this->Form->control('resource_search', [
        'label' => 'Find resources',
        'placeholder' => 'Search within this category…',
        'type' => 'search',
        'value' => $search,
        'x-interact:model.live' => 'search',
    ]) ?>
    <ul class="composition-choices">
        <?php foreach ($resources as $resource): ?>
            <li>
                <button
                    type="button"
                    :aria-pressed="$server.state.value === <?= (int)$resource->get('id') ?>"
                    @click="$server.call('choose', <?= (int)$resource->get('id') ?>)"
                >
                    <span><?= (string)h((string)$resource->get('title')) ?></span>
                    <span class="composition-choice-mark" aria-hidden="true">✓</span>
                </button>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($resources->count() === 0): ?>
        <p class="empty-state">No resources match your search. Try another title or category.</p>
    <?php endif; ?>
    <?= $this->Form->control('picker_notes', [
        'label' => 'Picker notes',
        'placeholder' => 'What caught your interest?',
        'value' => $notes,
        'x-interact:model' => 'notes',
    ]) ?>
    <p class="field-hint">Your notes stay here when you change categories.</p>
    <p class="field-error" role="alert" x-text="$server.errors.value ? $server.errors.value[0] : ''"></p>
</section>
