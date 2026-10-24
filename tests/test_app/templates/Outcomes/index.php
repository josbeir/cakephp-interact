<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Redirects and downloads · Interact');
?>
<header class="page-intro compact">
    <p class="eyebrow">Action outcomes</p>
    <h1>Redirects and downloads</h1>
    <p>Actions return redirect or download instructions after validation. Download requests authorize access independently.</p>
</header>
<?= $this->Interact->render('ActionOutcomes') ?>
