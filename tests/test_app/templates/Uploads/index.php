<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'File uploads · Interact');
?>
<header class="page-intro">
    <p class="eyebrow">Files / Native CakePHP validation</p>
    <h1>File uploads</h1>
    <p class="page-lede">
        Files upload to private temporary storage when selected.
        Your save action decides what becomes permanent.
    </p>
</header>
<p class="demo-note">
    This page uses the scoped endpoint <code>/uploads/update</code>.
    The default Interact endpoint supports the same upload operations.
</p>
<?= $this->Interact->render('DocumentUpload') ?>
