<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var int $progress
 * @var bool $running
 */
?>
<section class="demo-card polling-card" aria-label="Simulated job progress" x-interact:poll.5s="$server.state.running && $server.call('refreshStatus')">
    <div class="card-heading"><div><p class="eyebrow">06 / Conditional polling</p><h2>Refresh until the work is done.</h2></div><span class="capability">PHP actions</span></div>
    <p class="card-description">Each poll advances this simulated job by 25%. When PHP reports completion, the condition stops further requests. This example does not run a background worker.</p>
    <progress class="job-progress" aria-label="Simulated job completion" max="100" value="<?= (string)$progress ?>"><?= (string)$progress ?>%</progress>
    <p role="status"><?= $running ? 'Simulated job running: ' . (string)$progress . '%.' : 'Simulated job complete.' ?></p>
    <div class="polling-controls"><button type="button" @click="$server.call('restart')" :disabled="$server.pending()">Restart simulated job</button><span class="quiet" x-show="$server.loading('refreshStatus')" x-cloak>Checking progress…</span></div>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
