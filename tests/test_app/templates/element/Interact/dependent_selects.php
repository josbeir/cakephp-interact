<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var string $country
 * @var int|null $cityId
 * @var string $notes
 * @var bool $confirmed
 * @var iterable<\Cake\Datasource\EntityInterface> $cities
 * @var array<string, list<string>> $errors
 */
?>
<section class="demo-card" aria-label="Dependent choices">
    <div class="card-heading"><div><p class="eyebrow">02 / Connected fields</p><h2>Dependent choices</h2></div></div>
    <p class="card-description">Pick a country to load its cities. Your notes stay with you when the options change.</p>
    <form data-interact-submit="confirm">
        <label>
            Country
            <select data-interact-model="country" data-interact-update="change">
                <option value="BE" <?= $country === 'BE' ? 'selected' : '' ?>>Belgium</option>
                <option value="NL" <?= $country === 'NL' ? 'selected' : '' ?>>Netherlands</option>
            </select>
        </label>
        <label>
            City
            <select data-interact-model="cityId">
                <option value="">Choose a city</option>
                <?php foreach ($cities as $city): ?>
                    <option value="<?= (string)h((string)$city->get('id')) ?>" <?= $cityId === (int)$city->get('id') ? 'selected' : '' ?>><?= (string)h((string)$city->get('name')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            Notes
            <textarea data-interact-model="notes" rows="3" placeholder="Keep a little draft here…"><?= (string)h($notes) ?></textarea>
        </label>
        <?php foreach ($errors['cityId'] ?? [] as $error): ?>
            <p role="alert" class="field-error"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <button type="submit" class="button-primary" :disabled="$server.busy">Confirm city</button>
        <?php if ($confirmed): ?>
            <p class="success-message" role="status" x-show="!$server.dirty">City confirmed. Your notes are still here.</p>
        <?php endif; ?>
        <p class="request-error" role="alert" x-text="$server.error"></p>
    </form>
</section>
