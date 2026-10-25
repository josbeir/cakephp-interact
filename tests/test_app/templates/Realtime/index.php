<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var bool $ready
 */
$this->assign('title', 'Live chat · Interact');
?>
<div class="rt-page">
    <section class="rt-hero">
        <div class="rt-hero-copy">
            <p class="eyebrow">
                <span class="rt-hero-dot" aria-hidden="true"></span>
                Realtime / A working example
            </p>
            <h1>A little room to say hello<span>.</span></h1>
            <p class="intro-copy">
                Pick a name, send a message. Open another tab or share this page
                with a friend and watch the conversation arrive live.
            </p>
        </div>
        <a
            class="button rt-open-tab"
            href="/realtime"
            target="_blank"
            rel="noopener"
        >
            Open another tab
            <span aria-hidden="true">↗</span>
        </a>
    </section>
    <?= $this->Interact->render('RealtimeChat') ?>
    <?php if (!$ready): ?>
        <aside class="rt-setup rt-panel">
            <h2>Bring another tab into the conversation</h2>
            <p>Messages save here already. To receive them live in other tabs, start the preview with Docker:</p>
            <pre><code>composer preview</code></pre>
        </aside>
    <?php endif; ?>
</div>
