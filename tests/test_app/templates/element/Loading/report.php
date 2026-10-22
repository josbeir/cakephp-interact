<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Interact\View\TemplateView $this
 * @var string $heading
 */
?>
<section aria-label="<?= (string)h($heading) ?> placeholder" class="report-placeholder">
    <div class="report-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
    <p>This report has not loaded. No resource query has run.</p>
    <button type="button" @click="$server.sync()" :disabled="$server.busy">Load report</button>
    <span role="status" x-show="$server.busy">Loading report…</span>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
    <button
        type="button"
        x-show="$server.error && $server.error.recovery === 'retry'"
        @click="$server.sync()"
        :disabled="$server.busy"
    >Try again</button>
</section>
