<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<article
    class="rt-chat-message rt-assistant-message"
    aria-label="Demo assistant reply"
    x-data="chatReply"
    x-show="active"
    @interact:push.window="
        $event.target === $el.closest('[data-interact-id]') && receive($event.detail)
    "
    x-cloak
>
    <span class="rt-avatar" aria-hidden="true">✳</span>
    <div class="rt-message-body">
        <strong>Demo assistant</strong>
        <span class="rt-private-badge">Only your tabs · Scripted</span>
        <p x-interact:stream="chat-reply" aria-label="Assistant message"></p>
        <div
            class="rt-reply-progress"
            role="progressbar"
            aria-label="Reply progress"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-valuenow="progress"
        >
            <span :style="{ width: progress + '%' }"></span>
        </div>
        <div
            class="rt-reply-status"
            x-show="progress === 100"
            x-interact:stream.html="chat-reply-status"
        ></div>
    </div>
</article>
