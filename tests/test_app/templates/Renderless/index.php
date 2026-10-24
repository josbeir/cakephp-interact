<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 */
$this->assign('title', 'Renderless actions · Interact');
?>
<section class="page-intro">
    <p class="eyebrow">Renderless actions</p>
    <h1>Renderless actions</h1>
    <p class="intro-copy">Declared renderless actions accept state without replacing the form. Validation and conditional actions still render HTML.</p>
</section>
<section class="demo-card" aria-label="Renderless draft editor">
    <?= $this->Interact->render('DraftEditor', key: 'renderless-draft') ?>
</section>
