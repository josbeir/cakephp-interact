<?php
declare(strict_types=1);
use function Cake\Core\h;
/** @var \Interact\View\TemplateView $this */
/** @var \Cake\Datasource\EntityInterface $article */
/** @var string $title */
/** @var int $articleId */
/** @var string $editorKey */
?>
<?php if ($editorKey === 'monitor'): ?>
    <div class="rt-monitor">
        <article class="rt-published-card">
            <div class="rt-panel-heading rt-preview-heading">
                <div>
                    <span class="rt-kicker">Live preview</span>
                    <h2>The reader's view</h2>
                </div>
                <span class="rt-connection" :data-status="$server.realtime.status">
                    <span class="rt-status-dot" aria-hidden="true"></span>
                    <strong x-text="$server.realtime.status"></strong>
                </span>
            </div>
            <div class="rt-cover" aria-hidden="true">
                <span class="rt-cover-orbit"></span>
                <span class="rt-cover-mark">i<span>.</span></span>
                <span class="rt-cover-caption">Notes from the studio</span>
            </div>
            <div class="rt-published-body">
                <span class="rt-kicker">
                    Published article · <?= (string)$articleId ?>
                </span>
                <h2 aria-label="Published title">
                    <?= (string)h($article->get('title')) ?>
                </h2>
                <p>
                    This is a separate island. Publish a change here or in another tab
                    and watch this preview update.
                </p>
                <div class="rt-byline">
                    <span class="rt-avatar">E<?= (string)$article->get('owner_id') ?></span>
                    <span>
                        Editorial workspace
                        <small>Updated from the server</small>
                    </span>
                </div>
            </div>
        </article>
        <p
            class="rt-error"
            role="alert"
            x-show="$server.realtime.error"
            x-text="$server.realtime.error"
            x-cloak
        ></p>
        <div class="rt-observe-note">
            <span class="rt-note-icon" aria-hidden="true">↗</span>
            <div>
                <h3>Better with two tabs</h3>
                <p>
                    Edit in one, watch the other. Both views share the same published article.
                </p>
                <a href="/realtime" target="_blank" rel="noopener">
                    Open another tab
                    <span aria-hidden="true">↗</span>
                </a>
            </div>
        </div>
    </div>
<?php else: ?>
    <div
        class="rt-console"
        x-data="publishingDesk"
        @interact:push.window="
            $event.target === $el.closest('[data-interact-id]') && receive($event.detail)
        "
    >
        <section class="rt-panel rt-editor-panel" aria-label="Article editor">
            <div class="rt-panel-heading">
                <div>
                    <span class="rt-kicker">01 / Publish a change</span>
                    <h2>Your editorial workspace</h2>
                </div>
                <span class="rt-connection" :data-status="$server.realtime.status">
                    <span class="rt-status-dot" aria-hidden="true"></span>
                    <strong x-text="$server.realtime.status"></strong>
                </span>
            </div>
            <p class="rt-panel-copy">
                Give this article a new title. Every subscribed view will pick up the published change.
            </p>
            <?= $this->Form->create($article, ['x-interact:submit' => '$server.call("save")']) ?>
                <?= $this->Form->control('title', [
                    'label' => 'Draft title',
                    'id' => 'realtime-title-' . $articleId,
                    'type' => 'text',
                    'value' => $title,
                    'x-interact:model' => 'title',
                    'autocomplete' => 'off',
                ]) ?>
                <div class="rt-editor-actions">
                    <?= $this->Form->button('Publish changes', [
                        'class' => 'button-primary',
                        'aria-label' => 'Save article',
                        ':disabled' => '$server.pending("save")',
                    ]) ?>
                    <span
                        class="rt-draft-note"
                        role="status"
                        x-text="
                            $server.dirty
                                ? ($server.realtime.stale
                                    ? 'New version available · draft preserved'
                                    : 'Unpublished changes')
                                : 'Ready to edit'
                        "
                    ></span>
                </div>
            <?= $this->Form->end() ?>
            <div class="rt-current-title">
                <span>Currently published</span>
                <strong aria-label="Published title">
                    <?= (string)h($article->get('title')) ?>
                </strong>
            </div>
            <p
                class="rt-error"
                role="alert"
                x-show="$server.realtime.error"
                x-text="$server.realtime.error"
                x-cloak
            ></p>
        </section>

        <section class="rt-panel rt-inbox-panel" aria-label="Private inbox">
            <div class="rt-panel-heading">
                <div>
                    <span class="rt-kicker">02 / Send a notification</span>
                    <h2>Your private inbox</h2>
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
                    <span class="rt-kicker">03 / Watch work happen</span>
                    <h2>A report, as it happens</h2>
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
    </div>
<?php endif;
