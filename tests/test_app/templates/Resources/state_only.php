<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 */
?>
<header class="page-intro compact">
    <h1>Search without changing the URL.</h1>
    <p class="intro-copy">Filters and page choices stay out of the address bar. Reloading resets the listing.</p>
</header>
<?= $this->Interact->render('StateOnlySearch') ?>
