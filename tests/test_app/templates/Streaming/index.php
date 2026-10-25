<?php
declare(strict_types=1);
use TestApp\Island\Streaming\ReportIsland;
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Streaming · Interact');
?>
<section class="page-intro stream-intro">
    <p class="eyebrow">Server-sent events</p>
    <h1>Streaming</h1>
    <p class="intro-copy">One PHP action emits text, rendered elements, and JSON progress over SSE. A final render publishes the accepted state.</p>
</section>
<section class="demo-card stream-demo" aria-label="Streaming report">
    <?= $this->Interact->render(ReportIsland::class) ?>
</section>
<p class="stream-demo-note">The demo delays results to make incremental delivery visible.</p>
