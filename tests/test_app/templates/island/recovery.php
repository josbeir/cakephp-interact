<?php
declare(strict_types=1);
use Cake\Routing\Router;
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $message
 * @var int $saves
 */
?>
<section class="demo-card" aria-label="Failure recovery">
    <div class="card-heading"><div><p class="eyebrow">04 / Explicit recovery</p><h2>Request recovery</h2></div></div>
    <p class="card-description">Failed requests pause automatic updates and preserve local drafts. Recovery is explicit; this example does not persist data.</p>
    <?= $this->Form->create(null, ['url' => false, 'idPrefix' => 'Recovery', 'x-interact:submit' => '$server.call("save")']) ?>
        <?= $this->Form->control('message', [
            'type' => 'textarea',
            'rows' => 4,
            'label' => 'Recovery draft',
            'value' => $message,
            'x-interact:model.live' => 'message',
        ]) ?>
        <div class="form-footer">
            <?= $this->Form->button('Accept draft', ['class' => 'button-primary']) ?>
            <p class="quiet" role="status">Accepted <?= (string)$saves ?> times.</p>
        </div>
    <?= $this->Form->end() ?>
    <div class="button-row recovery-simulations">
        <button type="button" @click="$server.call('simulate', 'server')" :disabled="$server.busy">Simulate server failure</button>
        <button type="button" @click="$server.call('simulate', 'expired')" :disabled="$server.busy">Simulate expiry</button>
    </div>
    <div class="recovery-feedback" x-show="$server.paused" x-cloak>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
        <p>Automatic updates are paused. A failed operation may already have completed: check its result before submitting again.</p>
        <?= $this->Form->control('draftExport', [
            'type' => 'textarea',
            'rows' => 4,
            'label' => 'Draft export',
            'id' => 'recovery-draft-export',
            'readonly' => true,
            ':value' => '$server.exportDraft()',
        ]) ?>
        <p class="quiet">Select and copy the draft before leaving. Reloading does not restore it automatically.</p>
        <div class="button-row">
            <button type="button" @click="$server.sync()" x-show="$server.error && $server.error.recovery === 'retry'" :disabled="$server.busy">Synchronize latest draft</button>
            <a class="button button-text" href="<?= (string)h(Router::url(['_name' => 'demo:playground'])) ?>">Reload page</a>
        </div>
    </div>
</section>
