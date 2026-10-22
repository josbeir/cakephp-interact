<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var string $category
 * @var string $pickerLoading
 * @var array<string, string> $categories
 */
?>
<section class="composition-order" aria-label="Resource order">
    <?= $this->Form->create(null, [
        'url' => false,
        'class' => 'composition-layout',
        'x-interact:submit' => '$server.call("review")',
    ]) ?>
    <div class="side-stack">
        <div class="demo-card composition-category">
            <p class="eyebrow">01 · Narrow your choices</p>
            <h2>Resource order</h2>
            <p class="card-description">Choose a category to update the resource picker below.</p>
            <?= $this->Form->control('category', [
                'label' => 'Resource category',
                'options' => $categories,
                'value' => $category,
                'x-interact:model.change' => 'category',
            ]) ?>
        </div>
        <?= $this->Interact->render(
            'Composition/Picker',
            props: ['category' => $category],
            key: 'resource-picker',
            loading: $pickerLoading,
            model: 'data.resourceId',
        ) ?>
    </div>
    <div class="side-stack">
        <?= $this->Interact->render(
            'Composition/DateRange',
            key: 'date-range',
            model: ['start' => 'data.startsAt', 'end' => 'data.endsAt'],
        ) ?>
        <section class="demo-card composition-summary" aria-label="Selection summary">
            <p class="eyebrow">04 · Review your selection</p>
            <h2>Your reading plan</h2>
            <p class="card-description">Your choices appear here as you make them.</p>
            <dl class="composition-values">
                <div>
                    <dt>Resource ID</dt>
                    <dd
                        data-selection
                        x-text="$server.state.data.resourceId === null ? 'None' : $server.state.data.resourceId"
                    ></dd>
                </div>
                <div>
                    <dt>Date range</dt>
                    <dd data-range x-text="$server.state.data.startsAt + ' / ' + $server.state.data.endsAt"></dd>
                </div>
            </dl>
            <div class="button-row">
                <?= $this->Form->button('Review selection', ['type' => 'submit', 'class' => 'button-primary']) ?>
                <?= $this->Form->button('Clear selection', [
                    'type' => 'button',
                    'class' => 'button-text',
                    '@click' => '$server.call("clear")',
                ]) ?>
            </div>
            <p class="success-message" role="status" x-text="$server.state.reviewed"></p>
            <p
                class="field-error"
                role="alert"
                x-text="$server.errors['data.resourceId'] ? $server.errors['data.resourceId'][0] : ''"
            ></p>
            <p class="field-hint">Review checks your selection. This demo does not save a reading plan.</p>
        </section>
    </div>
    <?= $this->Form->end() ?>
</section>
