<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var int $count
 * @var int $generation
 * @var string $color
 */
?>
<section class="demo-card" aria-label="Morph protection">
    <div class="card-heading">
        <div>
            <p class="eyebrow">07 / DOM ownership</p>
            <h2>One render. Two different results.</h2>
        </div>
        <span class="capability">x-interact:ignore</span>
    </div>
    <p class="card-description">
        PHP sends the same count and color to both panels. Update them and watch
        the protected panel keep its original markup.
    </p>
    <div class="ownership-grid">
        <div class="ownership-panel swatch-<?= (string)h($color) ?>" aria-label="Normal region">
            <h3>Normal region</h3>
            <p class="render-count">Render count: <strong><?= $count ?></strong></p>
            <p>Color: <?= (string)h($color) ?></p>
        </div>
        <div
            id="protected-panel-<?= $generation ?>"
            class="ownership-panel swatch-<?= (string)h($color) ?>"
            aria-label="Ignored region"
            x-interact:ignore
        >
            <h3>Ignored region</h3>
            <p class="render-count">Render count: <strong><?= $count ?></strong></p>
            <p>Color: <?= (string)h($color) ?></p>
        </div>
    </div>
    <div class="widget-actions">
        <button
            type="button"
            class="button-primary"
            @click="$server.call('advance')"
            :disabled="$server.pending()"
        >
            Update from PHP
        </button>
        <button
            type="button"
            @click="$server.call('recreate')"
            :disabled="$server.pending()"
        >
            Recreate ignored region
        </button>
    </div>
    <p class="quiet">Recreating changes the protected panel’s ID, so its new markup can take effect.</p>
    <p
        class="request-error"
        role="alert"
        x-text="$server.error ? $server.error.message : ''"
    ></p>
</section>
