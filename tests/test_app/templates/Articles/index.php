<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $title
 */
?>
<h1><?= (string)h($title) ?></h1>
<?= $this->Interact->render('article-list') ?>
<?= $this->Interact->render('dependent-selects') ?>
