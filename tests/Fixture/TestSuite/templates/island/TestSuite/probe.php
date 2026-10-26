<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $label
 * @var string $title
 * @var int $count
 * @var int $construction
 * @var int $renders
 * @var string $marker
 */
?>
<section aria-label="<?= (string)h($label) ?>">
    <p><?= (string)h($title) ?></p>
    <p>Count: <?= (string)$count ?></p>
    <p>Construction: <?= (string)$construction ?>; renders: <?= (string)$renders ?></p>
    <p><?= (string)h($marker) ?>; <?= (string)h($this->getConfig('testingMarker', 'native view')) ?></p>
    <?= $this->Interact->render('Counter', props: ['label' => 'Nested test counter'], key: 'child') ?>
</section>
