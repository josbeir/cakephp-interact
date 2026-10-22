<?php
declare(strict_types=1);

/**
 * templates/island/job_progress.php
 *
 * @var int $progress
 * @var bool $running
 */
?>
<section
    x-data="{ autoRefresh: true }"
    x-interact:poll.2s.visible="autoRefresh && $server.state.running && $server.call('refreshStatus')"
>
    <progress max="100" value="<?= $progress ?>" aria-label="Job progress"></progress>
    <p role="status"><?= $running ? 'Running' : 'Completed' ?>: <?= $progress ?>%</p>
    <button type="button" @click="autoRefresh = !autoRefresh" x-text="autoRefresh ? 'Pause' : 'Resume'"></button>
    <button type="button" @click="$server.call('restart')">Restart job</button>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
