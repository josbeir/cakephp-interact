<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 */
$this->assign('title', 'State-only search · Interact');
?>
<header class="page-intro compact">
    <h1>State-only search</h1>
    <p class="intro-copy">Search and pagination remain in signed state. Reloading resets the listing; the URL is unchanged.</p>
</header>
<?= $this->Interact->render('StateOnlySearch') ?>
