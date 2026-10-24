<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $serverTime
 * @var string $timestamp
 */
?>
<section
    class="demo-card polling-card"
    aria-label="Server clock"
    x-data="{ autoRefresh: true }"
    x-interact:poll.5s="autoRefresh && $server.sync()"
>
    <div class="card-heading">
        <div>
            <p class="eyebrow">05 / Automatic refresh</p>
            <h2>Server polling</h2>
        </div>
        <span class="capability">Polling</span>
    </div>

    <p class="card-description">
        Polling synchronizes server time every five seconds. An Alpine condition
        pauses automatic requests; manual synchronization uses the same bridge.
    </p>

    <time class="polling-time" datetime="<?= (string)h($timestamp) ?>">
        <?= (string)h($serverTime) ?>
    </time>

    <div class="polling-controls">
        <label class="checkbox-label">
            <input type="checkbox" x-model="autoRefresh" checked>
            Automatically refresh the clock
        </label>
        <button
            type="button"
            @click="$server.sync()"
            :disabled="$server.pending()"
        >
            Refresh clock now
        </button>
    </div>

    <p class="quiet">Hidden tabs pause polling automatically.</p>
    <p
        class="request-error"
        role="alert"
        x-text="$server.error ? $server.error.message : ''"
    ></p>
</section>
