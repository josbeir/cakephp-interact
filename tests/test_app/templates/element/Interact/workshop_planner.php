<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
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
    <div class="card-heading"><div><p class="eyebrow">02 / Conditional forms</p><h2>Plan a workshop</h2></div><span class="capability">Live quote</span></div>
    <p class="card-description">Change the format, add extras, or request a company invoice. The server calculates your quote as you go.</p>
    <form data-interact-submit="confirm">
        <div class="field-grid">
            <label>Workshop format
                <select data-interact-model="format" data-interact-update="change">
                    <option value="remote" <?= $format === 'remote' ? 'selected' : '' ?>>Online · €45 / person</option>
                    <option value="studio" <?= $format === 'studio' ? 'selected' : '' ?>>In the studio · €75 / person</option>
                </select>
            </label>
            <label>Attendees
                <input type="number" min="1" max="12" value="<?= (string)$attendees ?>" data-interact-model="attendees" data-interact-update="change" <?= isset($errors['attendees']) ? 'aria-invalid="true"' : '' ?>>
            </label>
        </div>
        <fieldset class="choice-group"><legend>Make it yours</legend>
            <label class="check-option"><input type="checkbox" value="materials" data-interact-model="extras" data-interact-update="change" <?= in_array('materials', $extras, true) ? 'checked' : '' ?>><span>Printed materials <small>+ €15 per person</small></span></label>
            <label class="check-option"><input type="checkbox" value="recording" data-interact-model="extras" data-interact-update="change" <?= in_array('recording', $extras, true) ? 'checked' : '' ?>><span>Session recording <small>+ €30 once</small></span></label>
        </fieldset>
        <label class="check-option"><input type="checkbox" data-interact-model="business" data-interact-update="change" <?= $business ? 'checked' : '' ?>><span>Book for a company <small>Show invoice details</small></span></label>
        <?php if ($business): ?>
            <div class="conditional-field"><label>Company name<input value="<?= (string)h($company) ?>" maxlength="100" data-interact-model="company" placeholder="Your company" <?= isset($errors['company']) ? 'aria-invalid="true"' : '' ?>></label><p class="field-hint">Required for a company quote. Hidden when booking personally.</p></div>
        <?php endif; ?>
        <?php foreach ($errors as $messages): ?>
            <?php foreach ($messages as $message): ?>
                <p role="alert" class="field-error"><?= (string)h($message) ?></p>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="quote-summary" aria-live="polite"><div><span class="eyebrow">Estimated total</span><p class="quote-total">€<?= number_format($total, 2) ?></p></div><span class="quiet">€<?= (string)$rate ?> per person<br>plus your selected extras</span></div>
        <div class="form-footer"><button type="submit" class="button-primary" :disabled="$server.busy">Confirm quote</button><span class="quiet" x-show="$server.busy" x-cloak>Calculating…</span></div>
        <?php if ($confirmed): ?>
            <p class="success-message" role="status" x-show="!$server.dirty">Quote confirmed. This is a demo; no booking was made.</p>
        <?php endif; ?>
        <p class="request-error" role="alert" x-text="$server.error"></p>
    </form>
</section>
