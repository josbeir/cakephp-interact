<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro">
    <p class="eyebrow"><span class="live-dot" aria-hidden="true"></span>A few things you can build</p>
    <h1>Meet your next interaction.</h1>
    <p class="intro-copy">Small, working examples of server state, conditional forms, and dynamic lists. Change something and see what happens.</p>
    <p class="quiet">These examples live in the current page. Reload to start fresh.</p>
</header>
<section class="demo-card counter-card" aria-label="Independent counters">
    <div class="card-heading"><div><p class="eyebrow">01 / Isolated state</p><h2>Two islands. Their own pace.</h2></div><span class="capability">Typed actions</span></div>
    <p class="card-description">Both counters use the same PHP class with different starting values. Change one; its neighbor stays put.</p>
    <div class="counter-grid">
        <?= $this->Interact->render('Counter', props: ['label' => 'First counter', 'initial' => 0], key: 'first-counter') ?>
        <?= $this->Interact->render('Counter', props: ['label' => 'Second counter', 'initial' => 5], key: 'second-counter') ?>
    </div>
</section>
<div class="playground-grid">
    <?= $this->Interact->render('WorkshopPlanner') ?>
    <?= $this->Interact->render('TaskBoard') ?>
</div>
<?= $this->Interact->render('Recovery') ?>
<div class="playground-grid polling-examples">
    <?= $this->Interact->render('ServerClock') ?>
    <?= $this->Interact->render('JobProgress') ?>
</div>
<div class="widget-examples">
    <?= $this->Interact->render('MorphProtection') ?>
    <?= $this->Interact->render('EnhancedSelect') ?>
</div>
