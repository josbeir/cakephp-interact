<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * templates/element/Interact/article_form.php
 *
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $article
 * @var string $title
 * @var bool $saved
 * @var array<string, list<string>> $errors
 */
?>
<?= $this->Form->create($article, ['url' => false, 'x-interact:submit' => '$server.call("save")']) ?>
    <?= $this->Form->control('title', ['value' => $title, 'x-interact:model' => 'title']) ?>
    <?php foreach ($errors['_form'] ?? [] as $message): ?>
        <p role="alert"><?=(string)h($message) ?></p>
    <?php endforeach; ?>
    <?= $this->Form->button('Save article') ?>
<?= $this->Form->end() ?>
<?php if ($saved): ?>
    <p role="status" x-show="!$server.dirty">Article saved.</p>
<?php endif; ?>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
