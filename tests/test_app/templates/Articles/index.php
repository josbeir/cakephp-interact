<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $title
 */
?>
<header class="page-intro">
    <p class="eyebrow"><span class="live-dot" aria-hidden="true"></span>The interactive demo</p>
    <h1><?= (string)h($title) ?></h1>
    <p class="intro-copy">Search, edit, and connect parts of a page. CakePHP renders the HTML; each island takes care of its own interaction.</p>
    <div class="intro-links"><a class="button button-primary" href="/playground">Explore the playground <span aria-hidden="true">↗</span></a><span class="quiet">Try an edit below. No page reload needed.</span></div>
</header>
<div class="demo-columns">
    <?= $this->Interact->render('article-list') ?>
    <div class="side-stack">
        <?= $this->Interact->render('dependent-selects') ?>
        <aside class="note-card"><p class="eyebrow">Try this</p><h2>A draft of your own</h2><p>Edit an article without saving, then search for it. Its draft stays with that article while the surrounding list updates.</p><p>Saving emits an event that refreshes the parent list.</p></aside>
    </div>
</div>
