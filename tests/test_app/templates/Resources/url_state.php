<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'URL bindings · Interact');
?>
<header class="page-intro compact">
    <h1>URL bindings</h1>
    <p>Nested filters and nullable selections use ordinary State declarations.</p>
    <a href="/demo/url-state?first_q=navigated" x-interact:navigate>Navigate with new query</a>
</header>
<aside x-interact:persist="url-filters">
    <?= $this->Interact->render('UrlFilters', key: 'first', urlPrefix: 'first') ?>
</aside>
<?= $this->Interact->render('UrlFilters', props: ['controlPrefix' => 'second'], key: 'second', urlPrefix: 'second') ?>
