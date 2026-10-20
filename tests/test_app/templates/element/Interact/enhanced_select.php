<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $country
 * @var array<string, string> $countries
 * @var bool $confirmed
 * @var bool $visible
 * @var int $refreshes
 * @var array<string, list<string>> $errors
 */
?>
<section class="demo-card" aria-label="JavaScript select widget">
    <div class="card-heading">
        <div>
            <p class="eyebrow">08 / Application-owned JavaScript</p>
            <h2>A widget that feels at home.</h2>
        </div>
        <span class="capability">Alpine + Tom Select</span>
    </div>
    <p class="card-description">
        Search for a country. PHP can validate or reset your selection while
        the widget keeps ownership of its markup. This example does not save anything.
    </p>
    <?= $this->Form->create(null, [
        'url' => false,
        'x-interact:submit' => '$server.call("confirm")',
    ]) ?>
        <?php if ($visible): ?>
            <div
                id="country-widget"
                x-data="enhancedSelect"
                x-modelable="value"
                x-interact:model.change="country"
            >
                <div x-interact:ignore>
                    <?= $this->Form->control('country', [
                        'id' => 'widget-country',
                        'type' => 'select',
                        'label' => 'Search countries',
                        'options' => $countries,
                        'empty' => 'Choose a country',
                        'value' => $country,
                        'required' => false,
                        'aria-describedby' => 'widget-country-error',
                    ]) ?>
                </div>
            </div>
        <?php endif; ?>
        <p id="widget-country-error" class="field-error" role="alert"><?= (string)h(implode(' ', $errors['country'] ?? [])) ?></p>
        <div class="widget-actions">
            <?= $this->Form->button('Confirm country', ['class' => 'button-primary']) ?>
            <button
                type="button"
                @click="$server.call('resetSelection')"
                :disabled="$server.pending()"
            >
                Reset from PHP
            </button>
            <button
                type="button"
                @click="$server.call('refreshExample')"
                :disabled="$server.pending()"
            >
                Refresh surrounding content
            </button>
            <button
                type="button"
                @click="$server.call('toggleWidget')"
                :disabled="$server.pending()"
            >
                <?= $visible ? 'Remove widget' : 'Restore widget' ?>
            </button>
        </div>
        <p role="status">Surrounding content refreshed <?= $refreshes ?> times.</p>
        <?php if ($confirmed): ?>
            <p class="success-message" role="status">Confirmed: <?= (string)h($countries[$country] ?? $country) ?>.</p>
        <?php endif; ?>
        <p
            class="request-error"
            role="alert"
            x-text="$server.error ? $server.error.message : ''"
        ></p>
    <?= $this->Form->end() ?>
</section>
