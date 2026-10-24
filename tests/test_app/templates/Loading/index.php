<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Loading modes · Interact');
?>
<section class="page-intro">
    <p class="eyebrow">Deferred loading</p>
    <h1>Loading modes</h1>
    <p class="lede">Lazy, deferred, and manual mounts delay initialization and rendering. Each load request rechecks authorization.</p>
</section>
<?= $this->Interact->render('LoadingDashboard') ?>
