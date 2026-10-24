<?php
declare(strict_types=1);

use TestApp\Island\CounterIsland;

/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Playground · Interact');
?>
<header class="page-intro">
    <p class="eyebrow">State and actions</p>
    <h1>Playground</h1>
    <p class="intro-copy">Typed actions, conditional controls, and keyed collections. Each island synchronizes its own state.</p>
    <p class="quiet">Unsaved state resets on reload.</p>
</header>
<section class="demo-card counter-card" aria-label="Independent counters">
    <div class="card-heading"><div><p class="eyebrow">01 / Isolated state</p><h2>Independent counters</h2></div><span class="capability">Typed actions</span></div>
    <p class="card-description">Two mounts of the same class use distinct props and keys. Declared actions update only the targeted island.</p>
    <div class="counter-grid">
        <?= $this->Interact->render(CounterIsland::class, props: ['label' => 'First counter', 'initial' => 0], key: 'first-counter') ?>
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
