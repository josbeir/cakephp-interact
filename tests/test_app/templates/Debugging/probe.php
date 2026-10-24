<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var bool $templateDump
 * @var string $loading
 */
?>
<?= $this->Interact->render('DebuggingProbe', props: ['templateDump' => $templateDump], key: 'first', loading: $loading) ?>
<?= $this->Interact->render('DebuggingProbe', key: 'second') ?>
<a href="/navigation" x-interact:navigate>Leave debug probe</a>
