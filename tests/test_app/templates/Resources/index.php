<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Search and pagination · Interact');
?>
<header class="page-intro compact">
    <p class="eyebrow">Table finders and URL state</p>
    <h1>Search and pagination</h1>
    <p class="intro-copy">Filter, sort, and paginate 1,200 seeded resources through Table finders. URL-bound state restores filters and pages through browser history.</p>
    <p class="quiet">Search terms match resource titles and summaries.</p>
</header>
<?= $this->Interact->render('ResourceSearch') ?>
