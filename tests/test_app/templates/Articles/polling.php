<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<header class="page-intro"><h1>Polling acceptance fixtures</h1><p>Real entity forms and nested ownership under background refresh.</p></header>
<?= $this->Interact->render('Article/PollingEditor', props: ['articleId' => 1]) ?>
<?= $this->Interact->render('Article/PollingList') ?>
