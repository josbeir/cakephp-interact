<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $country
 * @var int|null $cityId
 * @var string $notes
 * @var bool $confirmed
 * @var \Cake\ORM\ResultSet<int, \Cake\ORM\Entity> $cities
 * @var array<string, list<string>> $errors
 */
?>
<section class="demo-card" aria-label="Dependent choices">
    <div class="card-heading"><div><p class="eyebrow">02 / Connected fields</p><h2>Dependent choices</h2></div></div>
    <p class="card-description">Pick a country to load its cities. Your notes stay with you when the options change.</p>
    <?= $this->Form->create(null, ['url' => false, 'idPrefix' => 'dependent-choices', 'data-interact-submit' => 'confirm']) ?>
        <?= $this->Form->control('country', [
            'label' => 'Country',
            'options' => ['BE' => 'Belgium', 'NL' => 'Netherlands'],
            'value' => $country,
            'data-interact-model' => 'country',
            'data-interact-update' => 'change',
        ]) ?>
        <?= $this->Form->control('cityId', [
            'label' => 'City',
            'type' => 'select',
            'options' => $cities->combine('id', 'name')->toArray(),
            'empty' => 'Choose a city',
            'value' => $cityId,
            'data-interact-model' => 'cityId',
            'aria-invalid' => isset($errors['cityId']) ? 'true' : null,
        ]) ?>
        <?= $this->Form->control('notes', [
            'type' => 'textarea',
            'label' => 'Notes',
            'value' => $notes,
            'rows' => 3,
            'placeholder' => 'Keep a little draft here…',
            'data-interact-model' => 'notes',
        ]) ?>
        <?php foreach ($errors['cityId'] ?? [] as $error): ?>
            <p role="alert" class="field-error"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <?= $this->Form->button('Confirm city', ['class' => 'button-primary', ':disabled' => '$server.busy']) ?>
        <?php if ($confirmed): ?>
            <p class="success-message" role="status" x-show="!$server.dirty">City confirmed. Your notes are still here.</p>
        <?php endif; ?>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
    <?= $this->Form->end() ?>
</section>
