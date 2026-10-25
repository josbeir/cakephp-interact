<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\ResultSetInterface<array-key, \Cake\Datasource\EntityInterface> $messages
 * @var bool $live
 */
?>
<div class="rt-feed" @chat-sent.window="$server.sync()">
    <div class="rt-feed-heading">
        <span>Latest messages</span>
        <?php if ($live): ?>
            <span class="rt-connection" :data-status="$server.realtime.status">
                <span class="rt-status-dot" aria-hidden="true"></span>
                <strong x-text="$server.realtime.status"></strong>
            </span>
        <?php else: ?>
            <span class="rt-connection">Local preview</span>
        <?php endif; ?>
    </div>
    <div class="rt-messages" role="log" aria-label="Chat messages" aria-live="polite" x-data="chatMessages">
        <?php if ($messages->isEmpty()): ?>
            <div class="rt-chat-empty">
                <span aria-hidden="true">↗</span>
                <h3>Every conversation starts with hello.</h3>
                <p>
                    <?php if ($live): ?>
                        Send the first message below. Open a second tab to see it arrive there too.
                    <?php else: ?>
                        Send a message below. Your saved conversation survives a reload.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
        <?php foreach ($messages as $entry): ?>
            <article class="rt-chat-message" id="chat-message-<?= (string)$entry->get('id') ?>">
                <span class="rt-avatar" aria-hidden="true">
                    <?= (string)h(mb_strtoupper(mb_substr((string)$entry->get('name'), 0, 1))) ?>
                </span>
                <div class="rt-message-body">
                    <strong><?= (string)h($entry->get('name')) ?></strong>
                    <p><?= (string)h($entry->get('message')) ?></p>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="rt-history-note">Saved history · Latest 50 messages</p>
    <p
        class="rt-error"
        role="alert"
        x-show="$server.realtime.error"
        x-text="$server.realtime.error"
        x-cloak
    ></p>
</div>
