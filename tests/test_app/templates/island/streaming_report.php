<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Interact\View\TemplateView $this
 * @var string $report
 * @var list<array{title: string, description: string}> $sections
 */
?>
<div
    class="stream-workspace"
    x-data="{ percent: 0 }"
    @interact:stream.window="
        $event.target === $el.closest('[data-interact-id]') &&
        $event.detail.name === 'progress' &&
        (percent = $event.detail.detail.percent)
    "
>
    <div class="stream-toolbar">
        <div class="stream-title">
            <span class="stream-mark" aria-hidden="true">↗</span>
            <div>
                <h2>Report runner</h2>
                <p>Text, HTML elements, and progress events.</p>
            </div>
        </div>
        <button
            type="button"
            class="button-primary stream-run"
            @click="$server.call('generate')"
            :disabled="$server.busy"
        >
            <span class="stream-run-icon" :class="{ 'is-running': $server.busy }" aria-hidden="true">↻</span>
            Generate report
        </button>
    </div>

    <div class="stream-progress-heading">
        <span class="stream-state" :class="{ 'is-live': $server.busy, 'is-complete': percent === 100 }" role="status">
            <span class="stream-state-dot" aria-hidden="true"></span>
            <span x-text="$server.busy ? 'Streaming live' : (percent === 100 ? 'Report complete' : 'Ready to run')">Ready to run</span>
        </span>
        <span class="stream-percentage"><span x-text="percent">0</span>%</span>
    </div>
    <progress class="stream-meter" max="100" value="0" :value="percent" aria-label="Report progress"></progress>

    <div class="stream-panels">
        <section class="stream-console" aria-label="Live text output">
            <div class="stream-panel-heading">
                <span>Text stream</span>
                <span class="stream-format">PLAIN TEXT</span>
            </div>
            <div class="stream-console-body" :class="{ 'is-receiving': $server.busy }">
                <div class="stream-empty" x-show="percent === 0 && !$server.busy">
                    <span class="stream-prompt" aria-hidden="true">&gt;_</span>
                    <p>No output received.</p>
                    <span>Run the action to open an SSE response.</span>
                </div>
                <pre class="stream-transcript" x-interact:stream="report"><?= (string)h($report) ?></pre>
                <span class="stream-cursor" x-show="$server.busy" x-cloak aria-hidden="true"></span>
            </div>
            <div class="stream-console-footer">
                <span class="stream-connection-dot" :class="{ 'is-connected': $server.busy }" aria-hidden="true"></span>
                <span x-text="$server.busy ? 'Connection open · receiving results' : (percent === 100 ? 'Stream closed · all results received' : 'Waiting for a request')">Waiting for a request</span>
            </div>
        </section>

        <section class="stream-results" aria-label="Rendered results">
            <div class="stream-panel-heading">
                <span>Rendered elements</span>
                <span class="stream-format">HTML</span>
            </div>
            <ul class="stream-result-list" x-interact:stream.html="rows">
                <?php if ($sections === []): ?>
                    <li class="stream-results-empty">
                        <span class="stream-empty-stack" aria-hidden="true"><i></i><i></i><i></i></span>
                        <p>No rendered results.</p>
                        <span>Results arrive as native CakePHP elements.</span>
                    </li>
                <?php else: ?>
                    <?php foreach ($sections as $index => $section): ?>
                        <?= $this->element('streaming_row', ['index' => $index + 1, ...$section]) ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </section>
    </div>

    <div class="stream-footer">
        <span class="stream-footer-symbol" aria-hidden="true">✳</span>
        <p x-text="$server.busy ? 'SSE response open; receiving incremental output.' : (percent === 100 ? 'Response complete; final state accepted.' : 'The action emits text, elements, and progress.')">The action emits text, elements, and progress.</p>
        <span class="stream-footer-tag">Text + HTML + progress</span>
    </div>
    <p class="request-error stream-request-error" role="alert" x-show="$server.error" x-text="$server.error ? $server.error.message : ''" x-cloak></p>
</div>
