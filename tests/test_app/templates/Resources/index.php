<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro compact">
    <p class="eyebrow"><span class="live-dot" aria-hidden="true"></span>A larger list, a little less searching</p>
    <h1>Find your next good read.</h1>
    <p class="intro-copy">Explore a sample library of guides on CakePHP, forms, testing, and more. Search as you type, narrow the topic, and find a read that fits your time.</p>
    <p class="quiet">Try “validation”, “CakePHP pagination”, or “keyboard”. Every word helps narrow the results.</p>
</header>
<?= $this->Interact->render('resource-search') ?>
