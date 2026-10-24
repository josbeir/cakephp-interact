<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $title
 */
$this->assign('title', $title . ' · Interact');
?>
<header class="page-intro">
    <p class="eyebrow">Nested state</p>
    <h1><?= (string)h($title) ?></h1>
    <p class="intro-copy">Nested article editors keep independent drafts. Saves dispatch an event that refreshes the parent listing.</p>
    <div class="intro-links"><a class="button button-primary" href="/playground">Open playground</a><span class="quiet">Server-rendered HTML with isolated state.</span></div>
</header>
<div class="demo-columns">
    <?= $this->Interact->render('Article/Listing') ?>
    <div class="side-stack">
        <?= $this->Interact->render('DependentSelects') ?>
        <aside class="note-card"><p class="eyebrow">State ownership</p><h2>Draft ownership</h2><p>Child keys preserve article drafts during parent search and pagination.</p><p>Saving dispatches an event; the parent then renders the updated listing.</p></aside>
    </div>
</div>
