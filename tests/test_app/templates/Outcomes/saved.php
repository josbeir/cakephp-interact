<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $article
 */
?>
<section class="demo-card">
    <p class="eyebrow">Saved article</p>
    <h1><?= (string)h($article->get('title')) ?></h1>
    <p>This is a normal CakePHP page reached after a successful island action.</p>
    <a href="/outcomes">Create another article</a>
</section>
