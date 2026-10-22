<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $article
 * @var int $savedCount
 * @var array<string, list<string>> $errors
 * @var int $signals
 */
$this->Form->setTemplates(['error' => '<div class="error-message field-error" id="{{id}}" role="alert">{{content}}</div>']);
?>
<div class="card-heading">
    <h2>Article draft</h2>
    <span class="capability">Explicit saves</span>
</div>
<p class="card-description">Save a valid draft repeatedly. The live count advances while the PHP count stays put. An invalid title renders FormHelper errors; correcting it renders once more to clear them.</p>
<?= $this->Form->create($article, [
    'url' => false,
    'x-interact:model' => 'data',
    'x-interact:submit' => '$server.call("saveDraft")',
]) ?>
    <?= $this->Form->control('title', ['label' => 'Draft title', 'required' => false]) ?>
    <?= $this->Form->control('body', ['label' => 'Draft body', 'type' => 'textarea', 'rows' => 4]) ?>
    <div class="form-footer">
        <?= $this->Form->button('Save draft', ['class' => 'button-primary']) ?>
        <span role="status" x-show="$server.loading('saveDraft')" x-cloak>Saving…</span>
    </div>
<?= $this->Form->end() ?>
<?php foreach ($errors['_form'] ?? [] as $message): ?>
    <p role="alert" class="field-error"><?= (string)h($message) ?></p>
<?php endforeach; ?>
<p
    role="alert"
    class="request-error"
    x-text="$server.error ? $server.error.message : ''"
></p>
<dl class="navigation-experiments">
    <div>
        <dt>Live save count</dt>
        <dd data-testid="live-saves" x-text="$server.state.savedCount"></dd>
    </div>
    <div>
        <dt>PHP save count at last render</dt>
        <dd data-testid="php-saves"><?= $savedCount ?></dd>
    </div>
    <div>
        <dt>Live signal count</dt>
        <dd data-testid="live-signals" x-text="$server.state.signals"></dd>
    </div>
    <div>
        <dt>PHP signal count at last render</dt>
        <dd data-testid="php-signals"><?= $signals ?></dd>
    </div>
</dl>
<div class="button-row">
    <button type="button" @click="$server.sync()">Refresh view</button>
    <button type="button" @click="$server.call('signal')">Send signal</button>
    <button type="button" @click="$server.call('signal', 5)">Send five signals and render</button>
</div>
