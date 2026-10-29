<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $format
 * @var int $attendees
 * @var array<array-key, mixed> $extras
 * @var bool $business
 * @var string $company
 * @var bool $confirmed
 * @var int $rate
 * @var int $total
 * @var array<string, list<string>> $errors
 */
?>
<section class="demo-card" aria-label="Workshop planner">
    <div class="card-heading"><div><p class="eyebrow">02 / Conditional forms</p><h2>Conditional form</h2></div><span class="capability">Live quote</span></div>
    <p class="card-description">Writable form state controls conditional fields. PHP computes the total and validates the submitted selection.</p>
    <?= $this->Form->create(null, ['url' => false, 'idPrefix' => 'workshop', 'x-interact:submit' => '$server.call("confirm")']) ?>
        <div class="field-grid">
            <?= $this->Form->control('format', [
                'label' => 'Workshop format',
                'options' => ['remote' => 'Online · €45 / person', 'studio' => 'In the studio · €75 / person'],
                'value' => $format,
                'x-interact:model.live' => 'format',
            ]) ?>
            <?= $this->Form->control('attendees', [
                'type' => 'number',
                'label' => 'Attendees',
                'min' => 1,
                'max' => 12,
                'value' => $attendees,
                'x-interact:model.change' => 'attendees',
                'aria-invalid' => isset($errors['attendees']) ? 'true' : null,
            ]) ?>
        </div>
        <fieldset class="choice-group"><legend>Make it yours</legend>
            <?= $this->Form->multiCheckbox('extras', [
                'materials' => 'Printed materials (+ €15 per person)',
                'recording' => 'Session recording (+ €30 once)',
            ], [
                'val' => $extras,
                'label' => ['class' => 'check-option'],
                'x-interact:model.live' => 'extras',
            ]) ?>
        </fieldset>
        <?= $this->Form->control('business', [
            'type' => 'checkbox',
            'label' => ['text' => 'Book for a company', 'class' => 'check-option'],
            'checked' => $business,
            'x-interact:model.live' => 'business',
        ]) ?>
        <?php if ($business): ?>
            <div class="conditional-field">
                <?= $this->Form->control('company', [
                    'type' => 'text',
                    'label' => 'Company name',
                    'value' => $company,
                    'maxlength' => 100,
                    'placeholder' => 'Your company',
                    'x-interact:model' => 'company',
                    'aria-invalid' => isset($errors['company']) ? 'true' : null,
                ]) ?>
                <p class="field-hint">Required for a company quote. Hidden when booking personally.</p>
            </div>
        <?php endif; ?>
        <?php foreach ($errors as $messages): ?>
            <?php foreach ($messages as $message): ?>
                <p role="alert" class="field-error"><?= (string)h($message) ?></p>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="quote-summary" aria-live="polite"><div><span class="eyebrow">Estimated total</span><p class="quote-total">€<?= number_format($total, 2) ?></p></div><span class="quiet">€<?= (string)$rate ?> per person<br>plus your selected extras</span></div>
        <div class="form-footer"><?= $this->Form->button('Confirm quote', ['class' => 'button-primary']) ?><span class="quiet" x-show="$server.loading()" x-cloak>Calculating…</span></div>
        <?php if ($confirmed): ?>
            <p class="success-message" role="status" x-show="!$server.dirty">Quote confirmed. This is a demo; no booking was made.</p>
        <?php endif; ?>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
    <?= $this->Form->end() ?>
</section>
