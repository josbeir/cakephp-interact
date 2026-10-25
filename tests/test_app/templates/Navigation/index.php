<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $page
 */
$this->assign('title', $page . ' · Navigation · Interact');
$this->append('script', '<meta name="description" content="' . (string)h($page) . ' navigation example">');
// Page styles are removed when absent; a stable id explicitly retains the shared accent.
$this->Html->css($page === 'Overview' ? 'navigation-overview' : 'navigation-details', ['block' => 'script']);
if ($page === 'Overview') {
    $this->append('script', $this->Html->tag('style', '.navigation-head-example { padding: 1rem; }', [
        'id' => 'navigation-shared-accent',
        'x-interact:asset' => 'persist',
    ]));
}
$edition = (string)$this->getRequest()->getQuery('edition', 'shared');
$loading = $this->getRequest()->getQuery('manual') === '1' ? 'manual' : 'eager';
?>
<section class="page-intro">
    <p class="eyebrow">Enhanced navigation</p>
    <h1><?= (string)h($page) ?></h1>
    <p class="intro-copy">Normal CakePHP GET routes replace page content. Persisted layout islands retain their clients and drafts.</p>
</section>
<div class="navigation-example">
    <aside class="demo-card navigation-notebook" x-interact:persist="notebook">
        <?= $this->Interact->render('NavigationNotebook', props: ['edition' => $edition], key: 'layout-notebook', loading: $loading) ?>
        <p class="field-hint">Scroll these references, then change pages. This layout region keeps its position.</p>
        <div class="navigation-scroll" role="region" tabindex="0" aria-label="Scrollable notebook references">
            <?php for ($line = 1; $line <= 20; $line++): ?>
                <p>Reference <?= $line ?></p>
            <?php endfor; ?>
        </div>
    </aside>
    <section class="demo-card navigation-page">
        <p class="eyebrow">Page-owned content</p>
        <nav class="navigation-links" aria-label="Navigation example">
            <a href="/navigation" x-interact:navigate.preserve-scroll>Overview</a>
            <a href="/navigation/details" x-interact:navigate.hover.preserve-scroll>Details</a>
        </nav>
        <section class="navigation-head-example" aria-label="Page styles">
            <strong><?= (string)h($page) ?> styles</strong>
            <p>The page stylesheet changes with these tabs. Its predecessor is removed; the shared padding persists by its style id.</p>
            <a href="/navigation/nonces" x-interact:navigate>Try rotating CSP nonces</a>
        </section>
        <h2>Page-owned state</h2>
        <p class="card-description">
            Add five to the counter, then switch between Overview and Details.
            The counter resets to zero because it belongs to the page.
            Your notebook on the left keeps its draft and remembered count.
        </p>
        <?= $this->Interact->render('Counter', props: ['label' => 'Page counter'], key: 'page-counter') ?>
        <section class="navigation-tools" aria-labelledby="navigation-tools-heading">
            <p class="eyebrow">Navigation options</p>
            <h2 id="navigation-tools-heading">Scroll, history, and mount identity</h2>
            <dl class="navigation-experiments">
                <div>
                    <dt><a href="/navigation/details#destination" x-interact:navigate>Jump to destination</a></dt>
                    <dd>Open Details and land at the section below. A fragment on the current page uses normal anchor navigation.</dd>
                </div>
                <div>
                    <dt><a href="/navigation/details" x-interact:navigate.preserve-scroll>Keep page scroll</a></dt>
                    <dd>Scroll down first, then follow this link. The destination keeps your current page position.</dd>
                </div>
                <div>
                    <dt><a href="/navigation/details?edition=new" x-interact:navigate>Change notebook props</a></dt>
                    <dd>Switch the notebook edition to <strong>new</strong>. Changing its server-owned prop mounts a fresh notebook instead of retaining the old one.</dd>
                </div>
            </dl>
            <p class="field-hint">These links demonstrate variations of the Details destination; only the two tabs above represent page selection.</p>
        </section>
        <section class="navigation-history-note">
            <p class="eyebrow">Browser history</p>
            <h2>History restoration</h2>
            <p>Enter a notebook URL filter, change pages, then use Back and Forward.
                The URL filter follows history, your unrelated notebook draft survives,
                and the page counter starts fresh on each page visit.</p>
        </section>
        <h2 id="destination">Destination section</h2>
        <p>Fragment navigation lands here. Back and Forward restore page scroll using fresh server HTML.</p>
    </section>
</div>
<details class="demo-card navigation-lifecycle" x-interact:persist="navigation-lifecycle">
    <summary>More lifecycle examples: uploads and polling</summary>
    <p class="card-description">Open this panel, select a temporary file or start polling, then switch pages above. These layout islands keep their existing clients.</p>
    <div class="navigation-example">
        <?= $this->Interact->render('DocumentUpload', key: 'layout-upload') ?>
        <?= $this->Interact->render('JobProgress', key: 'layout-job') ?>
    </div>
</details>
