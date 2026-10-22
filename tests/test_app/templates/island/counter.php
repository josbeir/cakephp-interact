<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var string $label
 * @var int $count
 * @var int $initial
 */
?>
<section class="counter" aria-label="<?= (string)h($label) ?>">
    <div class="counter-heading"><h3><?= (string)h($label) ?></h3><span class="quiet">Starts at <?= (string)$initial ?></span></div>
    <p class="counter-value" aria-live="polite" aria-atomic="true"><span class="sr-only">Count: </span><?= (string)$count ?></p>
    <div class="button-row">
        <button type="button" @click="$server.call('adjust', -1)" aria-label="Decrease by one">−1</button>
        <button type="button" @click="$server.call('adjust', 1)" aria-label="Increase by one">+1</button>
        <button type="button" class="button-primary" @click="$server.call('adjust', 5)">Add five</button>
        <button type="button" class="button-text" @click="$server.call('reset')">Reset</button>
    </div>
    <p class="field-hint">A server-owned value, bounded between 0 and 99.</p>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
