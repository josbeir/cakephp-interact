<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var int $progress
 * @var bool $running
 */
?>
<section
    class="demo-card polling-card"
    aria-label="Simulated job progress"
    x-data="{ autoRefresh: true }"
    x-interact:poll.5s="
        autoRefresh && $server.state.running && $server.call('refreshStatus')
    "
>
    <div class="card-heading">
        <div>
            <p class="eyebrow">06 / Conditional polling</p>
            <h2>Conditional polling</h2>
        </div>
        <span class="capability">PHP actions</span>
    </div>

    <p class="card-description">
        Each poll advances this simulated job by 25%.
        When PHP reports completion, the condition stops further requests.
        This example does not run a background worker.
    </p>

    <progress
        class="job-progress"
        aria-label="Simulated job completion"
        max="100"
        value="<?= (string)$progress ?>"
    >
        <?= (string)$progress ?>%
    </progress>

    <p role="status">
        <?php if ($running): ?>
            Simulated job running: <?= (string)$progress ?>%.
        <?php else: ?>
            Simulated job complete.
        <?php endif; ?>
    </p>

    <div class="polling-controls">
        <button
            type="button"
            @click="autoRefresh = !autoRefresh"
            :aria-pressed="!autoRefresh"
            :disabled="!$server.state.running"
            x-text="autoRefresh ? 'Pause polling' : 'Resume polling'"
        >
            Pause polling
        </button>
        <button
            type="button"
            @click="$server.call('restart')"
            :disabled="$server.pending()"
        >
            Restart simulated job
        </button>
        <span class="quiet" x-show="$server.loading('refreshStatus')" x-cloak>
            Checking progress…
        </span>
    </div>

    <p
        class="request-error"
        role="alert"
        x-text="$server.error ? $server.error.message : ''"
    ></p>
</section>
