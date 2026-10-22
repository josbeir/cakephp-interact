<?php
declare(strict_types=1);
/**
 * templates/island/article_redirect.php
 *
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $article
 * @var string $title
 */
?>
<?= $this->Form->create($article, ['url' => false, 'x-interact:submit' => '$server.call("save")']) ?>
    <?= $this->Form->control('title', ['value' => $title, 'x-interact:model' => 'title']) ?>
    <?= $this->Form->button('Save and view article') ?>
<?= $this->Form->end() ?>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
