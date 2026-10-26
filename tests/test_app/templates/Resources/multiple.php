<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Multiple paginators · Interact');
?>
<header class="page-intro compact">
    <h1>Multiple paginators</h1>
    <p>One island owns two listings, each with its own page size, sorting, and query aliases.</p>
</header>
<?= $this->Interact->render('LibraryOverview') ?>
