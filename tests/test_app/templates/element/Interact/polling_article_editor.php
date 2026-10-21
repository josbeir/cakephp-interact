<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var int $articleId
 */
?>
<section aria-label="Polling article editor" x-interact:poll.1s>
    <?= $this->element('Interact/article_editor', ['formPrefix' => 'polling-article-' . (string)$articleId]) ?>
</section>
