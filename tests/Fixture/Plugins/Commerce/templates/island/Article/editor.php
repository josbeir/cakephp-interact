<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var int $count
 * @var \Interact\Discovery\Declaration|null $child
 */
$child ??= null;
?>
<p>Plugin editor: <?= (string)$count ?></p>
<?= $this->element('marker') ?>
<?php if (isset($child)) { ?>
    <?= $this->island($child, ['count' => $count]) ?>
    <?= $this->element('marker') ?>
<?php }
