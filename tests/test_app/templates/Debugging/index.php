<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Debugging · Interact');
?>
<section class="page-intro">
    <p class="eyebrow">Native CakePHP tools</p>
    <h1>Debugging</h1>
    <p class="intro-copy">
        Edit a draft and call PHP's familiar debug() function during island updates.
        Captured output opens in the Island debug viewer; the normal interaction continues.
    </p>
</section>
<section class="demo-card" aria-label="Debugging playground">
    <?= $this->Interact->render('Debugging') ?>
</section>
<p class="quiet">
    Application debug mode enables capture. Hide or clear the viewer with its controls.
    Streaming pauses briefly between dumps so you can follow progress.
</p>
