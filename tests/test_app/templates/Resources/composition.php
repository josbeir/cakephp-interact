<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var string $pickerLoading
 */
$this->assign('title', 'Child inputs · Interact');
?>
<header class="page-intro compact">
    <p class="eyebrow">Parent and child state</p>
    <h1>Child inputs</h1>
    <p class="intro-copy">A parent owns the array draft. Reactive props filter a child picker; modelable state returns the selection and date range.</p>
</header>
<?= $this->Interact->render('Composition/Order', props: ['pickerLoading' => $pickerLoading]) ?>
