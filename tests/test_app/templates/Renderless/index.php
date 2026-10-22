<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 */
$this->assign('title', 'Renderless actions · Interact');
?>
<section class="page-intro">
    <p class="eyebrow">Renderless actions</p>
    <h1>Save the draft. Keep the view.</h1>
    <p class="intro-copy">Save through CakePHP without rebuilding this form. Alpine feedback follows accepted state; PHP feedback updates when the view renders.</p>
</section>
<section class="demo-card" aria-label="Renderless draft editor">
    <?= $this->Interact->render('DraftEditor', key: 'renderless-draft') ?>
</section>
