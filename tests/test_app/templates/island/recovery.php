<?php
declare(strict_types=1);
use Cake\Routing\Router;
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $message
 * @var int $saves
 * @var array<string, list<string>> $errors
 */
?>
<section
    class="demo-card"
    aria-label="Failure recovery"
    x-data="{ draftToRestore: '', restored: false, restoreOpen: false }"
    @draft-restored.window="restored = true"
>
    <div class="card-heading">
        <div>
            <p class="eyebrow">04 / Explicit recovery</p>
            <h2>Request recovery</h2>
        </div>
    </div>
    <p class="card-description">
        Failed requests pause automatic updates and preserve local drafts.
        Recovery is explicit; this example does not persist data.
    </p>
    <?= $this->Form->create(null, [
        'url' => false,
        'idPrefix' => 'Recovery',
        'x-interact:submit' => '$server.call("save")',
    ]) ?>
        <?= $this->Form->control('message', [
            'type' => 'textarea',
            'rows' => 4,
            'label' => 'Recovery draft',
            'value' => $message,
            'x-interact:model.live' => 'message',
            '@input' => 'restored = false',
        ]) ?>
        <div class="form-footer">
            <?= $this->Form->button('Accept draft', [
                'class' => 'button-primary',
                ':disabled' => '$server.paused',
            ]) ?>
            <p class="quiet" role="status">Accepted <?= (string)$saves ?> times.</p>
        </div>
    <?= $this->Form->end() ?>
    <div class="button-row recovery-simulations">
        <button type="button" @click="$server.call('simulate', 'server')" :disabled="$server.busy || $server.paused">
            Simulate server failure
        </button>
        <button type="button" @click="$server.call('simulate', 'expired')" :disabled="$server.busy || $server.paused">
            Simulate expiry
        </button>
        <button type="button" @click="$server.call('expireSignIn')" :disabled="$server.busy || $server.paused">
            Expire demo sign-in
        </button>
    </div>
    <div class="recovery-feedback" x-show="$server.paused" x-cloak>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
        <p>
            Automatic updates are paused. A failed operation may already have completed:
            check its result before submitting again.
        </p>
        <?= $this->Form->control('draftExport', [
            'type' => 'textarea',
            'rows' => 4,
            'label' => 'Draft export',
            'id' => 'recovery-draft-export',
            'readonly' => true,
            ':value' => '$server.exportDraft()',
        ]) ?>
        <p class="quiet">
            Select and copy the draft before leaving. After reloading or signing in,
            use Restore a copied draft to review it. No draft is stored automatically.
        </p>
        <div class="button-row">
            <button
                type="button"
                @click="$server.sync()"
                x-show="$server.error && $server.error.recovery === 'retry'"
                :disabled="$server.busy"
            >Synchronize latest draft</button>
            <a
                class="button button-text"
                href="<?= (string)h(Router::url(['_name' => 'demo:recovery-sign-in'])) ?>"
                x-show="$server.error && $server.error.recovery === 'authenticate'"
            >Sign in again</a>
            <a class="button button-text" href="<?= (string)h(Router::url(['_name' => 'demo:playground'])) ?>">
                Reload page
            </a>
        </div>
    </div>
    <div x-show="!$server.paused">
        <button
            type="button"
            @click="restoreOpen = !restoreOpen"
            :aria-expanded="restoreOpen"
            aria-controls="recovery-restore-form"
        >Restore a copied draft</button>
        <div id="recovery-restore-form" x-show="restoreOpen" x-cloak>
            <p>Paste the exported JSON from this example. Restoration changes the draft only; review it before accepting.</p>
            <?= $this->Form->create(null, [
                'url' => false,
                'x-interact:submit' => '$server.call("restoreDraft", draftToRestore)',
            ]) ?>
                <?= $this->Form->control('copiedDraft', [
                    'type' => 'textarea',
                    'rows' => 4,
                    'label' => 'Copied draft JSON',
                    'id' => 'recovery-copied-draft',
                    'x-model' => 'draftToRestore',
                ]) ?>
                <?php if (($errors['_form'] ?? []) !== []): ?>
                    <ul class="request-error" role="alert">
                        <?php foreach ($errors['_form'] ?? [] as $error): ?>
                            <li><?= (string)h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?= $this->Form->button('Restore draft for review') ?>
            <?= $this->Form->end() ?>
        </div>
    </div>
    <p class="quiet" x-show="restored" x-cloak>Draft restored for review. Nothing has been accepted yet.</p>
</section>
