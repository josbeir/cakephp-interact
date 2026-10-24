<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro compact">
    <p class="eyebrow">Form-level models</p>
    <h1>Article form</h1>
    <p>Native names bind to array drafts. CakePHP marshals, validates, and saves each article explicitly.</p>
</header>
<section aria-label="FormHelper article">
    <h2>FormHelper</h2>
    <?= $this->Interact->render('Article/Form') ?>
</section>
<section aria-label="HTML article">
    <h2>HTML</h2>
    <?= $this->Interact->render('Article/NativeForm') ?>
</section>
