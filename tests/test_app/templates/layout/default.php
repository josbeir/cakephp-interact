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
    <title><?= (string)h($this->fetch('title') ?: 'Interact · Demo') ?></title>
    <?php if ($currentPath === '/playground'): ?>
        <link rel="stylesheet" href="/js/widgets.css">
        <script src="/js/widgets.js" defer></script>
    <?php endif; ?>
    <link rel="stylesheet" href="/demo.css">
    <?php if (str_starts_with($currentPath, '/realtime')): ?>
        <link rel="stylesheet" href="/realtime.css">
        <script src="/realtime.js" defer></script>
    <?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="/interact-mark.svg">
    <?= $this->fetch('script') ?>
</head>
<body>
    <div
        x-data
        x-show="$store.interactNavigation.loading()"
        x-cloak
        class="navigation-progress"
        role="status"
        aria-label="Loading page"
    ></div>
    <a class="skip-link" href="#main">Skip to content</a>
    <header
        class="site-sidebar"
        x-interact:persist="site-navigation"
        x-data="{ menuOpen: false }"
        @keydown.escape="menuOpen = false"
    >
        <a class="brand" href="/" aria-label="Interact home">
            <img class="brand-mark" src="/interact-mark.svg" alt="" width="44" height="44">
            <span>Interact</span>
        </a>
        <button
            type="button"
            class="menu-toggle"
            x-ref="menuToggle"
            @click="menuOpen = !menuOpen"
            :aria-expanded="menuOpen"
            aria-controls="demo-navigation"
            x-cloak
        >Menu</button>
        <nav
            id="demo-navigation"
            class="site-navigation"
            aria-label="Demo pages"
            :class="{ 'is-collapsed': !menuOpen }"
            @click="menuOpen = false"
            @keydown.escape="$refs.menuToggle.focus()"
        >
            <?php foreach (['/' => 'Embedded islands', '/playground' => 'Playground', '/forms' => 'Article form', '/search' => 'Search', '/composition' => 'Child inputs', '/uploads' => 'Uploads', '/outcomes' => 'Action outcomes', '/loading' => 'Lazy loading', '/page' => 'Page island', '/navigation' => 'Navigation', '/renderless' => 'Renderless actions', '/streaming' => 'Streaming', '/realtime' => 'Realtime', '/debugging' => 'Debugging'] as $path => $label): ?>
                <a
                    href="<?= (string)h($path) ?>"
                    x-interact:navigate<?= $path === '/navigation' ? '.trail' : '' ?>
                    <?= $currentPath === $path ? 'aria-current="page"' : '' ?>><?= (string)h($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="version-tag">Development build</span>
    </header>
    <main id="main" class="page-shell">
        <?= $this->fetch('content') ?>
    </main>
    <footer class="site-footer"><span>CakePHP Interact</span><span>PHP 8.5 · CakePHP 6</span></footer>
</body>
</html>
