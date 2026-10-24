<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Page island · Interact');
?>
<header class="page-intro compact">
    <p class="eyebrow">Controller integration</p>
    <h1>Page island</h1>
    <p class="intro-copy">The controller action template renders as one island. Search and pagination replace its content while the shared layout remains mounted.</p>
</header>
<?= $this->element('Article/listing') ?>
