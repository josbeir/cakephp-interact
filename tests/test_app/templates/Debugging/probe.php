<?php
declare(strict_types=1);
use TestApp\Island\Debugging\ProbeIsland;
/**
 * @var \Interact\View\TemplateView $this
 * @var bool $templateDump
 * @var string $loading
 */
?>
<?= $this->Interact->render(ProbeIsland::class, props: ['templateDump' => $templateDump], key: 'first', loading: $loading) ?>
<?= $this->Interact->render(ProbeIsland::class, key: 'second') ?>
<a href="/navigation" x-interact:navigate>Leave debug probe</a>
