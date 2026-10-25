<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $message
 * @var string $progress
 * @var array<string, list<string>> $errors
 */
?>
<div class="card-heading">
    <h2>Inspect an island draft</h2>
    <span class="capability">debug()</span>
</div>
<p class="card-description">
    Each button dumps the current message from PHP. Nothing is saved.
    Clear the message before validating to inspect a rejected draft.
</p>
<?= $this->Form->create(null, ['url' => false, 'x-interact:submit' => '$server.call("inspect")']) ?>
    <?= $this->Form->control('message', [
        'label' => 'Debug message',
        'value' => $message,
        'required' => false,
        'x-interact:model' => 'message',
        'aria-describedby' => 'debug-message-error',
        'aria-invalid' => isset($errors['message']) ? 'true' : 'false',
    ]) ?>
    <p id="debug-message-error" class="field-error" role="alert"><?= (string)h(implode(' ', $errors['message'] ?? [])) ?></p>
    <div class="button-row">
        <?= $this->Form->button('Dump draft', ['class' => 'button-primary', ':disabled' => '$server.pending()']) ?>
        <button type="button" @click="$server.call('inspectStream')" :disabled="$server.pending()">
            Stream three dumps
        </button>
        <button type="button" @click="$server.call('inspectOnly')" :disabled="$server.pending()">
            Dump without rendering
        </button>
        <button type="button" @click="$server.call('inspectValidation')" :disabled="$server.pending()">
            Dump and validate
        </button>
        <button type="button" @click="$server.call('inspectFailure')" :disabled="$server.pending()">
            Dump then fail
        </button>
    </div>
<?= $this->Form->end() ?>
<dl class="navigation-experiments">
    <div>
        <dt>Accepted inspections</dt>
        <dd x-text="$server.state.inspections"></dd>
    </div>
    <div>
        <dt>Current result</dt>
        <dd role="status" x-text="$server.state.status"></dd>
    </div>
</dl>
<pre x-interact:stream="progress" aria-label="Debug stream progress"><?= (string)h($progress) ?></pre>
<p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
<button type="button" x-show="$server.error" x-cloak @click="$server.sync()">
    Resume after failure
</button>
