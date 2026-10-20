<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro">
    <p class="eyebrow">Files / Native CakePHP validation</p>
    <h1>Choose files. Save when ready.</h1>
    <p class="page-lede">
        Files upload to private temporary storage when selected.
        Your save action decides what becomes permanent.
    </p>
</header>
<p class="demo-note">
    This example also demonstrates an optional application-owned endpoint at <code>/uploads/update</code>.
    Uploads work with Interact's default endpoint too; no custom controller action is required.
</p>
<?= $this->Interact->render('document-upload') ?>
