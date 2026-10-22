<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * templates/island/Article/form.php
 *
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $article
 * @var array{title: string, body: string, published: bool, topics: list<string>, comments: list<array{body: string, approved: bool}>} $data
 * @var bool $saved
 * @var array<string, list<string>> $errors
 */
$this->Form->setTemplates([
    'error' => '<div class="error-message field-error" id="{{id}}" role="alert">{{content}}</div>',
]);
?>
<?= $this->Form->create($article, [
    'url' => false,
    'id' => 'article-draft',
    'x-interact:model' => 'data',
    'x-interact:submit' => '$server.call("save")',
]) ?>
    <?= $this->Form->control('title') ?>
    <?= $this->Form->control('body', ['type' => 'textarea', 'rows' => 5]) ?>
    <?= $this->Form->control('published', [
        'type' => 'checkbox',
        'label' => ['text' => 'Published', 'class' => 'check-option'],
    ]) ?>
    <fieldset class="choice-group">
        <legend>Topics</legend>
        <?= $this->Form->multiCheckbox('topics', ['cakephp' => 'CakePHP', 'php' => 'PHP'], [
            'label' => ['class' => 'check-option'],
        ]) ?>
    </fieldset>
    <div class="article-rows">
        <?php foreach (array_keys($data['comments']) as $index): ?>
            <fieldset class="article-editor">
                <legend class="row-id">Comment <?= (int)$index + 1 ?></legend>
                <?= $this->Form->control('comments.' . $index . '.body', [
                    'label' => 'Comment ' . ($index + 1) . ' text',
                ]) ?>
                <?= $this->Form->control('comments.' . $index . '.approved', [
                    'type' => 'checkbox',
                    'label' => ['text' => 'Approve comment ' . ($index + 1), 'class' => 'check-option'],
                ]) ?>
                <?= $this->Form->button('Remove comment ' . ($index + 1), [
                    'type' => 'button',
                    'class' => 'button-text',
                    '@click' => '$server.call("removeComment", ' . $index . ')',
                ]) ?>
            </fieldset>
        <?php endforeach; ?>
    </div>
    <div class="form-footer">
        <?= $this->Form->button('Add comment', [
            'type' => 'button',
            'class' => 'button-text',
            '@click' => '$server.call("addComment")',
        ]) ?>
    </div>
    <?php foreach ($errors['_form'] ?? [] as $message): ?>
        <p role="alert" class="field-error"><?= (string)h($message) ?></p>
    <?php endforeach; ?>
    <div class="form-footer">
        <?= $this->Form->button('Save article', ['class' => 'button-primary']) ?>
        <span class="quiet" x-show="$server.loading('save')" x-cloak>Saving…</span>
    </div>
<?= $this->Form->end() ?>
<?php if ($saved): ?>
    <p role="status" class="success-message" x-show="!$server.dirty">Article saved.</p>
<?php endif; ?>
<p role="alert" class="request-error" x-text="$server.error ? $server.error.message : ''"></p>
