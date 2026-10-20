<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro compact">
    <p class="eyebrow"><span class="live-dot" aria-hidden="true"></span>One page, one island</p>
    <h1>Whole-page listing</h1>
    <p class="intro-copy">The same article listing, rendered through the controller action's template. Search and pagination update the content while the surrounding layout stays in place.</p>
</header>
<?= $this->element('Interact/article_list') ?>
