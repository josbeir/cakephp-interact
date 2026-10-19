<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var string $country
 * @var int|null $cityId
 * @var string $notes
 * @var iterable<\Cake\Datasource\EntityInterface> $cities
 * @var array<string, list<string>> $errors
 */
?>
<section aria-label="Dependent choices">
    <h2>Dependent choices</h2>
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
            <input data-interact-model="notes" value="<?= (string)h($notes) ?>">
        </label>
        <?php foreach ($errors['cityId'] ?? [] as $error): ?>
            <p role="alert"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <button type="submit">Confirm city</button>
    </form>
</section>
