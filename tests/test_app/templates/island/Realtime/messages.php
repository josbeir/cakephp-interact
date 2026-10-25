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
    <div class="rt-history">
        <?php if ($messages->isEmpty()): ?>
            <div class="rt-chat-empty">
                <h3>No messages yet.</h3>
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
                <header class="rt-message-meta">
                    <strong><?= (string)h($entry->get('name')) ?></strong>
                    <?php if ($entry->get('recipient') !== null): ?>
                        <span class="rt-private-badge">Only your tabs</span>
                    <?php endif; ?>
                </header>
                <p><?= (string)h($entry->get('message')) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
    <p
        class="rt-error"
        role="alert"
        x-show="$server.realtime.error"
        x-text="$server.realtime.error"
        x-cloak
    ></p>
</div>
