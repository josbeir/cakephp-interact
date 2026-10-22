<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var string $pickerLoading
 */
?>
<header class="page-intro compact">
    <p class="eyebrow"><span class="live-dot"></span> Child inputs</p>
    <h1>Reusable island inputs</h1>
    <p class="intro-copy">A parent form owns the draft. Its children supply a resource selection and a date range.</p>
</header>
<?= $this->Interact->render('Composition/Order', props: ['pickerLoading' => $pickerLoading]) ?>
