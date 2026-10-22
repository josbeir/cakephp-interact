<?php
declare(strict_types=1);

/**
 * templates/island/country_picker.php
 *
 * @var \Interact\View\TemplateView $this
 * @var string $country
 * @var bool $confirmed
 */
?>
<?= $this->Form->create(null, ['url' => false, 'x-interact:submit' => '$server.call("confirm")']) ?>
    <div x-data="countryPicker" x-modelable="selection" x-interact:model.change="country">
        <div x-interact:ignore>
            <?= $this->Form->control('country', [
                'id' => 'picker-country',
                'label' => 'Country',
                'options' => ['BE' => 'Belgium', 'NL' => 'Netherlands'],
                'empty' => 'Choose a country',
                'value' => $country,
            ]) ?>
        </div>
    </div>
    <ul role="alert">
        <template x-for="(message, index) in ($server.errors.country || [])" :key="index">
            <li x-text="message"></li>
        </template>
    </ul>
    <?= $this->Form->button('Confirm country') ?>
    <button type="button" @click="$server.call('resetSelection')">Reset selection</button>
<?= $this->Form->end() ?>
<?php if ($confirmed): ?>
    <p role="status" x-show="!$server.dirty">Country confirmed.</p>
<?php endif; ?>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
