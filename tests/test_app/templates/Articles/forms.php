<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Article form · Interact');
?>
<header class="page-intro compact">
    <p class="eyebrow">Form-level models</p>
    <h1>Article form</h1>
    <p>Form-level binding synchronizes an array draft. CakePHP marshals, validates, and saves the article and its comments.</p>
</header>
<div class="demo-columns">
    <section class="demo-card article-form-demo" aria-label="FormHelper article">
        <div class="card-heading">
            <div>
                <p class="eyebrow">01 / Nested drafts</p>
                <h2>Article draft</h2>
            </div>
            <span class="capability">FormHelper</span>
        </div>
        <p class="card-description">Native control names map to nested draft paths. The save action explicitly persists the associated records.</p>
        <?= $this->Interact->render('Article/Form') ?>
    </section>
    <aside class="note-card">
        <p class="eyebrow">Validation and persistence</p>
        <h2>Associated records</h2>
        <p>Adding or removing a comment preserves the other draft fields.</p>
        <p>Validation errors render from the failed entity without discarding the draft.</p>
        <p>Saving creates an article and its comments in SQLite.</p>
    </aside>
</div>
