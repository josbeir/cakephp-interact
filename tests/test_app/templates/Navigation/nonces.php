<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $page
 */
$this->assign('title', $page . ' · Interact');
$color = $page === 'Nonce overview' ? '#a78bfa' : '#67e8f9';
$this->append('script', $this->Html->tag('style', '.nonce-example { color: ' . $color . '; }', [
    'nonce' => $this->getRequest()->getAttribute('cspStyleNonce'),
]));
?>
<section class="page-intro">
    <p class="eyebrow">Rotating CSP nonces</p>
    <h1><?= (string)h($page) ?></h1>
    <p class="intro-copy">Each GET issues fresh script and style nonces. Enhanced navigation keeps the active browser policy and maps authorized styles to its original nonce.</p>
</section>
<section class="demo-card">
    <nav class="navigation-links" aria-label="Nonce example">
        <a href="/navigation/nonces" x-interact:navigate>Nonce overview</a>
        <a href="/navigation/nonces/details" x-interact:navigate>Nonce details</a>
    </nav>
    <h2 class="nonce-example">This color comes from a nonce-authorized page style.</h2>
    <p>The counter mounts fresh on each page. Use Back and Forward to test nonce rotation during history restoration.</p>
    <?= $this->Interact->render('Counter', props: ['label' => 'Nonce page counter'], key: 'nonce-page-counter') ?>
    <p><a href="/navigation" x-interact:navigate>Return to navigation examples</a></p>
</section>
