<?php
declare(strict_types=1);

/**
 * templates/element/Interact/dependent_selects.php
 *
 * @var \Interact\View\TemplateView $this
 * @var string $country
 * @var int|null $cityId
 * @var bool $confirmed
 * @var \Cake\Collection\CollectionInterface $cities
 */
?>
<?= $this->Form->create(null, ['url' => false, 'x-interact:submit' => '$server.call("confirm")']) ?>
    <?= $this->Form->control('country', [
        'options' => ['BE' => 'Belgium', 'NL' => 'Netherlands'],
        'value' => $country,
        'x-interact:model.change' => 'country',
    ]) ?>
    <?= $this->Form->control('cityId', [
        'label' => 'City',
        'options' => $cities->combine('id', 'name')->toArray(),
        'empty' => 'Choose a city',
        'value' => $cityId,
        'x-interact:model' => 'cityId',
    ]) ?>
    <ul role="alert">
        <template x-for="(message, index) in ($server.errors.cityId || [])" :key="index">
            <li x-text="message"></li>
        </template>
    </ul>
    <?= $this->Form->button('Confirm') ?>
<?= $this->Form->end() ?>
<?php if ($confirmed): ?>
    <p role="status">Selection confirmed.</p>
<?php endif; ?>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
