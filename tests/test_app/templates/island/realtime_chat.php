<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var string $name
 * @var string $message
 * @var bool $live
 */
?>
<div
    class="rt-console<?= $live ? '' : ' rt-console-local' ?>"
    x-data="chatDemo"
    @interact:push.window="
        $event.target === $el.closest('[data-interact-id]') && receive($event.detail)
    "
>
    <section class="rt-panel rt-chat-panel" aria-label="Shared chat">
        <div class="rt-panel-heading">
            <div>
                <span class="rt-kicker">The common room</span>
                <h2>Say hello.</h2>
            </div>
            <span class="rt-room-label">Shared conversation</span>
        </div>
        <?= $this->Interact->render('RealtimeMessages', props: ['live' => $live], key: 'chat-messages') ?>
        <?= $this->Form->create(null, ['x-interact:submit' => '$server.call("send")', 'class' => 'rt-composer']) ?>
            <?= $this->Form->control('name', [
                'label' => 'Your name',
                'value' => $name,
                'x-interact:model' => 'name',
                'maxlength' => 40,
                'autocomplete' => 'nickname',
                ':aria-invalid' => '!!$server.errors.name',
                'aria-describedby' => 'chat-name-error',
            ]) ?>
            <div class="rt-compose-row">
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
            <p class="rt-compose-hint" id="chat-hint">
                Press Enter to send. Everyone with this demo link can see the conversation.
            </p>
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
    <?php if ($live): ?>
        <aside class="rt-tools" aria-label="More realtime examples">
            <section class="rt-panel rt-inbox-panel" aria-label="Private inbox">
                <div class="rt-panel-heading">
                    <div>
                        <span class="rt-kicker">Private events</span>
                        <h2>A private nudge</h2>
                    </div>
                    <span
                        class="rt-counter"
                        x-text="notificationCount"
                        aria-label="Notifications received"
                    >0</span>
                </div>
                <div class="rt-inbox-empty" x-show="notificationCount === 0">
                    <span class="rt-inbox-icon" aria-hidden="true">↘</span>
                    <strong>A little quiet in here.</strong>
                    <p>Send a private update to this workspace and its other open tabs.</p>
                </div>
                <div class="rt-inbox-message" x-show="notificationCount > 0" x-cloak>
                    <span class="rt-message-icon" aria-hidden="true">✓</span>
                    <div>
                        <strong>Workspace update</strong>
                        <p
                            role="status"
                            aria-label="Private notification"
                            x-text="notification"
                        ></p>
                        <small>Private · Delivered live</small>
                    </div>
                </div>
                <button
                    type="button"
                    class="rt-wide-button"
                    @click="$server.call('notify')"
                    :disabled="$server.pending('notify')"
                >
                    Send private notification
                    <span aria-hidden="true">↗</span>
                </button>
            </section>

            <section class="rt-panel rt-report-panel" aria-label="Live report">
                <div class="rt-panel-heading">
                    <div>
                        <span class="rt-kicker">Streamed output</span>
                        <h2>A live chat recap</h2>
                    </div>
                    <span class="rt-report-percent" x-text="progress + '%'">0%</span>
                </div>
                <p class="rt-panel-copy">
                    Each stage arrives while the report runs. Your other tabs receive the same progress.
                </p>
                <div
                    class="rt-progress"
                    role="progressbar"
                    aria-label="Report progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuenow="progress"
                >
                    <span :style="{ width: progress + '%' }"></span>
                </div>
                <pre class="rt-report-log" x-interact:stream="realtime-output">Ready when you are.</pre>
                <ul class="rt-report-results" x-interact:stream.html="realtime-rows"></ul>
                <button
                    type="button"
                    class="rt-wide-button"
                    @click="$server.call('output')"
                    :disabled="$server.pending('output')"
                >
                    Generate report
                    <span aria-hidden="true">↗</span>
                </button>
            </section>
        </aside>
    <?php endif; ?>
</div>
