<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Dynamic events · Interact');
?>
<header class="page-intro">
    <p class="eyebrow">Scoped browser events</p>
    <h1>Dynamic event names</h1>
    <p class="intro-copy">Save either article to update its matching card. Change the selected card's watched article to try two listeners for the same record.</p>
</header>
<div class="events-demo">
    <section class="demo-card event-source" aria-label="Event source">
        <p class="eyebrow">01 / Dispatch</p>
        <h2>Save either article</h2>
        <p class="card-description">Each editor publishes its own record-specific event. Compare the counters as you save.</p>
        <?php foreach ([1, 2] as $articleId): ?>
            <div class="event-source-editor">
                <?= $this->Interact->render(
                    'Article/Editor',
                    props: ['articleId' => $articleId],
                ) ?>
                <div class="event-source-route">
                    <span>Emits on save</span>
                    <code>article-saved:<?= (string)$articleId ?></code>
                </div>
            </div>
        <?php endforeach; ?>
        <p class="quiet event-source-tip">To reach both cards with one event, select article 2 on the right and save article 2 again.</p>
    </section>
    <?= $this->Interact->render('Article/EventPanel') ?>
</div>
