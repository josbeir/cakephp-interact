<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Streaming · Interact');
?>
<section class="page-intro stream-intro">
    <p class="eyebrow">Streaming / Live demo</p>
    <h1>A report.<br><span>In real time.</span></h1>
    <p class="intro-copy">Press generate. Watch the text flow, results land, and progress move. All from one PHP action.</p>
</section>
<section class="demo-card stream-demo" aria-label="Streaming report">
    <?= $this->Interact->render('StreamingReport') ?>
</section>
<p class="stream-demo-note">Results are deliberately paced for this demo. In your application, stream them as your work produces them.</p>
