<?php
declare(strict_types=1);
/** @var \Interact\View\TemplateView $this */
?>
<article
    class="rt-chat-message rt-assistant-message"
    aria-label="Demo assistant reply"
    x-data="{ progress: null }"
    x-show="progress !== null"
    x-interact:on:reply-progress="progress = $event.detail.percent"
    x-cloak
>
    <header class="rt-message-meta">
        <strong>Demo assistant</strong>
        <span class="rt-private-badge">Only your tabs · Scripted</span>
    </header>
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
</article>
