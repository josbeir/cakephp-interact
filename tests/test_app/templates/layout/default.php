<?php
declare(strict_types=1);
use function Cake\Core\h;

/** @var \Interact\View\TemplateView $this */
$currentPath = $this->getRequest()->getUri()->getPath();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Interact · CakePHP playground</title>
    <link rel="stylesheet" href="/demo.css">
    <?php if ($currentPath === '/playground'): ?>
        <link rel="stylesheet" href="/js/widgets.css">
        <script src="/js/widgets.js" defer></script>
    <?php endif; ?>
    <?= $this->fetch('script') ?>
</head>
<body>
    <a class="skip-link" href="#main">Skip to examples</a>
    <header class="site-header">
        <a class="brand" href="/" aria-label="Interact home"><span class="brand-mark" aria-hidden="true">i.</span>interact<span class="brand-context">/ CakePHP</span></a>
        <nav aria-label="Demo pages">
            <?php foreach (['/' => 'Embedded islands', '/playground' => 'Playground', '/search' => 'Search library', '/uploads' => 'Uploads', '/outcomes' => 'Action outcomes', '/page' => 'Whole-page island'] as $path => $label): ?>
                <a href="<?= (string)h($path) ?>" <?= $currentPath === $path ? 'aria-current="page"' : '' ?>><?= (string)h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="version-tag">Development preview</span>
    </header>
    <main id="main" class="page-shell">
        <?= $this->fetch('content') ?>
    </main>
    <footer class="site-footer"><span>Rendered in PHP. Interactive in the browser.</span><span>CakePHP Interact · Demo lab</span></footer>
</body>
</html>
