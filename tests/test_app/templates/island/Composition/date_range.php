<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var string $start
 * @var string $end
 */
?>
<section class="demo-card" aria-label="Date range">
    <p class="eyebrow">03 · Modelable dates</p>
    <h2>Date range</h2>
    <p class="card-description">Set a date range, or start with a suggested month.</p>
    <div class="composition-dates">
        <?= $this->Form->control('reading_start', [
            'type' => 'date',
            'label' => 'Start date',
            'value' => $start,
            'x-interact:model' => 'start',
        ]) ?>
        <?= $this->Form->control('reading_end', [
            'type' => 'date',
            'label' => 'End date',
            'value' => $end,
            'x-interact:model' => 'end',
        ]) ?>
    </div>
    <button type="button" class="button-text" @click="$server.call('preset')">Use January</button>
</section>
