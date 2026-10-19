<?php
declare(strict_types=1);

/** @var \Interact\View\TemplateView $this */
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Interact demo</title>
    <style>
        body { font: 1rem system-ui; max-width: 60rem; margin: 2rem auto; padding: 1rem; }
        section, article { border: 1px solid #bbb; padding: 1rem; margin: 1rem 0; }
        label { display: block; margin: 0.75rem 0; }
        button, input, select { font: inherit; padding: 0.4rem; }
        [role="alert"] { color: #a00; }
    </style>
    <?= $this->fetch('script') ?>
</head>
<body>
    <nav><a href="/">Embedded islands</a> · <a href="/page">Whole-page island</a></nav>
    <?= $this->fetch('content') ?>
</body>
</html>
