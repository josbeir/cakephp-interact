<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Interact\View\TemplateView $this
 * @var string $heading
 * @var string $search
 * @var int $total
 * @var int $refreshes
 * @var int $mounts
 */
?>
<section aria-label="<?= (string)h($heading) ?>" class="resource-report"
    x-data="{ automatic: false }"
    x-interact:poll.5s="automatic && $server.sync()"
>
    <p class="report-total"><strong><?= $this->Number->format($total) ?></strong> matching resources</p>
    <label>Search report
        <input type="search" value="<?= (string)h($search) ?>" x-interact:model.live="search">
    </label>
    <div class="button-row">
        <button type="button" @click="$server.call('refreshReport')">Refresh report</button>
        <span role="status">Refreshed <?= $refreshes ?> times · Mounted <?= $mounts ?> time</span>
    </div>
    <label class="report-auto"><input type="checkbox" x-model="automatic"> Automatically refresh report</label>
    <p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
