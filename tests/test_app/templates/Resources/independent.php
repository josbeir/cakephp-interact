<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro compact">
    <h1>Independent searches</h1>
    <p>Each list owns its query parameters. Reading notes stay local during Back and Forward.</p>
</header>
<?= $this->Interact->render('ResourceSearch', props: ['controlPrefix' => 'articles'], key: 'articles', urlPrefix: 'articles') ?>
<?= $this->Interact->render('ResourceSearch', props: ['controlPrefix' => 'products'], key: 'products', urlPrefix: 'products') ?>
