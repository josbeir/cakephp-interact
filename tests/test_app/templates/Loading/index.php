<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<section class="page-intro">
    <p class="eyebrow">Deferred loading</p>
    <h1>Load the content when it is needed.</h1>
    <p class="lede">The page renders first. Each report runs its query only after its own loading trigger.</p>
</section>
<?= $this->Interact->render('loading-dashboard') ?>
