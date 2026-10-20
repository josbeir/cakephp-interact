<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $title
 * @var int $articleId
 * @var bool $saved
 * @var \Cake\Datasource\EntityInterface $article
 * @var array<string, list<string>> $errors
 */
?>
<article class="article-editor" aria-label="Article <?= (string)h((string)$articleId) ?>">
    <div class="row-heading"><span class="row-id">Article / <?= str_pad((string)$articleId, 2, '0', STR_PAD_LEFT) ?></span><span class="draft-indicator" x-show="$server.dirty" x-cloak>Unsaved changes</span></div>
    <?= $this->Form->create($article, ['url' => false, 'id' => 'article-form-' . (string)$articleId, 'idPrefix' => 'article-' . (string)$articleId, 'x-interact:submit' => '$server.call("save")']) ?>
        <?= $this->Form->control('title', [
            'type' => 'text',
            'label' => 'Article title',
            'value' => $title,
            'x-interact:model' => 'title',
            'templates' => ['error' => '<div class="field-error" id="{{id}}" role="alert">{{content}}</div>'],
        ]) ?>
        <?php foreach ($errors['_form'] ?? [] as $error): ?>
            <p role="alert" class="field-error"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <div class="form-footer"><?= $this->Form->button('Save article') ?><span class="quiet" x-show="$server.loading('save')" x-cloak>Saving…</span>
        <?php if ($saved): ?>
            <p class="success-message" role="status" x-show="!$server.dirty">Saved.</p>
        <?php endif; ?>
        </div>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
    <?= $this->Form->end() ?>
</article>
