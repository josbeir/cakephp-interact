<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
$this->assign('title', 'Recovery sign-in · Interact');
?>
<header class="page-intro">
    <p class="eyebrow">Recovery / Fresh session</p>
    <h1>Demo sign-in</h1>
    <p class="intro-copy">
        This example renews a demonstration session without credentials.
        Your application supplies its own authentication.
    </p>
</header>
<section class="demo-card" aria-label="Recovery sign-in">
    <h2>Return to a fresh page</h2>
    <p>
        Copy your exported draft before leaving the editor.
        After continuing, open Restore a copied draft and paste it for review.
        Signing in does not restore or accept the draft automatically.
    </p>
    <?= $this->Form->create(null, ['url' => ['_name' => 'demo:recovery-sign-in-complete']]) ?>
        <?= $this->Form->button('Continue demo sign-in', ['class' => 'button-primary']) ?>
    <?= $this->Form->end() ?>
</section>
