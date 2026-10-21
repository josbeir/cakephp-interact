<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro compact">
    <p class="eyebrow">Form-level models</p>
    <h1>Article and comments</h1>
    <p>Write an article, choose its topics, and add comments. Save the draft with CakePHP validation.</p>
</header>
<div class="demo-columns">
    <section class="demo-card article-form-demo" aria-label="FormHelper article">
        <div class="card-heading">
            <div>
                <p class="eyebrow">01 / Nested drafts</p>
                <h2>Write an article</h2>
            </div>
            <span class="capability">FormHelper</span>
        </div>
        <p class="card-description">Keep article fields and comments in one draft. Add rows as you write, then save when you're ready.</p>
        <?= $this->Interact->render('article-form') ?>
    </section>
    <aside class="note-card">
        <p class="eyebrow">Try the form</p>
        <h2>A draft with room to grow.</h2>
        <p>Add a comment, keep writing, then remove a row. Your other fields stay in place.</p>
        <p>Each comment needs text. Validation keeps your draft open so you can correct it and save again.</p>
        <p>Saving creates an article and its comments in the demo database.</p>
    </aside>
</div>
