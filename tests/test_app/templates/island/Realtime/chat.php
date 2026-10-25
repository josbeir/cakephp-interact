<?php
declare(strict_types=1);
use TestApp\Island\Realtime\MessagesIsland;
use TestApp\Island\Realtime\ReplyIsland;
/**
 * @var \Interact\View\TemplateView $this
 * @var string $name
 * @var bool $private
 * @var string $message
 * @var bool $live
 */
?>
<div
    class="rt-console"
    x-data="{ activity: '' }"
    x-interact:on:message-sent="activity = 'Private message received in your tabs.'"
>
    <section class="rt-panel rt-chat-panel" aria-label="Shared chat">
        <div class="rt-panel-heading">
            <div>
                <h2>The common room</h2>
                <p class="rt-history-note">Saved history · Latest 50 messages</p>
            </div>
            <?php if ($live): ?>
                <span class="rt-connection" :data-status="$server.realtime.status">
                    <span class="rt-status-dot" aria-hidden="true"></span>
                    <strong x-text="$server.realtime.status"></strong>
                </span>
            <?php else: ?>
                <span class="rt-connection">Local preview</span>
            <?php endif; ?>
        </div>
        <div
            class="rt-conversation"
            role="log"
            aria-label="Chat messages"
            aria-live="polite"
            x-data="chatMessages"
        >
            <?= $this->Interact->render(MessagesIsland::class, props: ['live' => $live], key: 'chat-messages') ?>
            <?php if ($live): ?>
                <?= $this->Interact->render(ReplyIsland::class, key: 'chat-reply') ?>
            <?php endif; ?>
        </div>
        <?= $this->Form->create(null, ['x-interact:submit' => '$server.call("send")', 'class' => 'rt-composer']) ?>
            <div class="rt-compose-row">
                <?= $this->Form->control('name', [
                    'label' => 'Your name',
                    'value' => $name,
                    'x-interact:model' => 'name',
                    'maxlength' => 40,
                    'autocomplete' => 'nickname',
                    ':aria-invalid' => '!!$server.errors.name',
                    'aria-describedby' => 'chat-name-error',
                ]) ?>
                <?= $this->Form->control('message', [
                    'label' => 'Message',
                    'value' => $message,
                    'x-interact:model' => 'message',
                    'placeholder' => 'Say hello…',
                    'maxlength' => 500,
                    'autocomplete' => 'off',
                    'aria-describedby' => 'chat-hint chat-message-error',
                    ':aria-invalid' => '!!$server.errors.message',
                ]) ?>
                <?= $this->Form->button('Send message', [
                    'class' => 'button-primary',
                    ':disabled' => '$server.pending("send")',
                ]) ?>
            </div>
            <p
                class="rt-field-error"
                id="chat-name-error"
                role="alert"
                x-text="$server.errors.name ? $server.errors.name[0] : ''"
            ></p>
            <p
                class="rt-field-error"
                id="chat-message-error"
                role="alert"
                x-text="$server.errors.message ? $server.errors.message[0] : ''"
            ></p>
            <p
                class="rt-field-error"
                role="alert"
                x-text="$server.error ? $server.error.message : ''"
            ></p>
            <div class="rt-composer-options">
                <label class="rt-private-option">
                    <input type="checkbox" x-interact:model="private"<?= $private ? ' checked' : '' ?>>
                    Only my tabs
                </label>
                <?php if ($live): ?>
                    <button
                        type="button"
                        class="rt-assistant-button"
                        @click="$server.call('reply')"
                        :disabled="$server.pending('reply')"
                    >
                        Ask demo assistant
                    </button>
                <?php endif; ?>
                <p class="rt-compose-hint" id="chat-hint">
                    Enter to send. Private messages stay in your tabs.
                </p>
            </div>
            <p class="rt-activity" role="status" x-text="activity"></p>
            <p
                class="rt-delivery-note"
                role="status"
                x-show="$server.state.deliveryIssue"
                x-cloak
            >
                Your message is saved. Live delivery is unavailable; other tabs can refresh to see it.
            </p>
        <?= $this->Form->end() ?>
    </section>
</div>
