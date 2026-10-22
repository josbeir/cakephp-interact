<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var int $refreshes
 * @var bool $showManual
 */
?>
<section aria-label="Loading dashboard" class="loading-dashboard">
    <div class="demo-card loading-controls">
        <div>
            <p class="eyebrow">Parent island</p>
            <h2>Independent reports</h2>
            <p>Parent refreshed <strong><?= $refreshes ?></strong> times. Child drafts and loading progress stay in place.</p>
        </div>
        <div class="button-row">
            <button type="button" @click="$server.call('refreshDashboard')">Refresh dashboard</button>
            <button type="button" @click="$server.call('toggleManual')">
                <?= $showManual ? 'Remove manual report' : 'Restore manual report' ?>
            </button>
        </div>
    </div>
    <section class="demo-card loading-report" aria-label="Deferred report">
        <p class="eyebrow">01 / After startup</p>
        <h2>Deferred report</h2>
        <p class="card-description">This report loads after Alpine starts, independently of the surrounding page.</p>
        <?= $this->Interact->render('ResourceReport', props: ['heading' => 'Deferred resource report'], key: 'deferred', urlPrefix: 'deferred', loading: 'defer') ?>
    </section>
    <?php if ($showManual): ?>
        <section class="demo-card loading-report" aria-label="Manual report">
            <p class="eyebrow">02 / On demand</p>
            <h2>Manual report</h2>
            <p class="card-description">This query waits until you choose to load it. Refresh the dashboard first to see the placeholder survive.</p>
            <?= $this->Interact->render('ResourceReport', props: ['heading' => 'Manual resource report'], key: 'manual', urlPrefix: 'manual', loading: 'manual', placeholder: 'Loading/report') ?>
        </section>
    <?php endif; ?>
    <section class="demo-card" x-data="{ open: false }" aria-label="Hidden report">
        <p class="eyebrow">03 / Inside a closed panel</p>
        <h2>Visibility includes hidden panels</h2>
        <p class="card-description">An island inside a closed panel waits until you open it.</p>
        <button type="button" @click="open = !open" :aria-expanded="open">Toggle report panel</button>
        <div x-show="open" class="loading-panel">
            <?= $this->Interact->render('ResourceReport', props: ['heading' => 'Hidden resource report'], key: 'hidden', urlPrefix: 'hidden', loading: 'lazy') ?>
        </div>
    </section>
    <div class="loading-scroll-hint">
        <p class="eyebrow">04 / Below the fold</p>
        <h2>Scroll to load the last report.</h2>
        <p>Its placeholder has not run mount(), viewData(), or a resource query yet.</p>
        <a href="#visible-report">Go to the lazy report ↓</a>
    </div>
    <section id="visible-report" class="demo-card loading-report" aria-label="Lazy report">
        <p class="eyebrow">Only when visible</p>
        <h2>Lazy report</h2>
        <?= $this->Interact->render('ResourceReport', props: ['heading' => 'Visible resource report'], key: 'visible', urlPrefix: 'visible', loading: 'lazy') ?>
    </section>
</section>
