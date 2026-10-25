<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var bool $ready
 * @var int $articleId
 */
$this->assign('title', 'Live publishing desk · Interact');
?>
<div class="rt-page">
    <section class="rt-hero">
        <div class="rt-hero-copy">
            <p class="eyebrow">
                <span class="rt-hero-dot" aria-hidden="true"></span>
                Realtime / A working example
            </p>
            <h1>The live publishing desk<span>.</span></h1>
            <p class="intro-copy">
                One workspace. Every view in sync. Publish an edit, send a private update,
                or follow a report as it comes together.
            </p>
            <div class="rt-hero-tags">
                <span>Cross-tab updates</span>
                <span>Private notifications</span>
                <span>Live progress</span>
            </div>
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
    <?php if ($ready): ?>
        <div class="rt-grid">
            <div class="rt-workspace">
                <?= $this->Interact->render('RealtimeArticle', props: ['articleId' => $articleId]) ?>
            </div>
            <aside class="rt-preview" aria-label="Live article preview">
                <?= $this->Interact->render(
                    'RealtimeArticle',
                    props: ['articleId' => $articleId, 'editorKey' => 'monitor'],
                    key: 'realtime-monitor',
                ) ?>
            </aside>
        </div>
        <p class="rt-footer-note">
            <span aria-hidden="true">◌</span>
            Two independent islands. One shared connection. Your application stays in control.
        </p>
    <?php else: ?>
        <section class="rt-setup rt-panel">
            <span class="rt-kicker">Bring the desk to life</span>
            <h2>Start the realtime preview</h2>
            <p>With Docker running, launch the workspace and its local Mercure hub:</p>
            <pre><code>npm run demo:realtime</code></pre>
            <p>
                Then open this page in two tabs to try live publishing, private notifications,
                and report progress.
            </p>
        </section>
    <?php endif; ?>
</div>
