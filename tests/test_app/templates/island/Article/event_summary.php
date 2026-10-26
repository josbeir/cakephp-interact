<?php
declare(strict_types=1);

use Cake\Utility\Text;
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var int $articleId
 * @var string $label
 * @var string $title
 * @var int $refreshes
 * @var string $notes
 */
?>
<section class="demo-card event-receiver" aria-label="<?= (string)h($label) ?>">
    <div class="event-receiver-heading">
        <div>
            <p class="eyebrow">Event listener</p>
            <h2><?= (string)h($label) ?></h2>
        </div>
        <span class="event-record-id">#<span data-article-id><?= (string)$articleId ?></span></span>
    </div>
    <div class="event-listening">
        <span aria-hidden="true"></span>
        <code>article-saved:<?= (string)$articleId ?></code>
    </div>
    <h3 class="event-article-title" data-article-title><?= (string)h($title) ?></h3>
    <div class="event-counter<?= $refreshes > 0 ? ' has-events' : '' ?>">
        <strong data-refreshes><?= (string)$refreshes ?></strong>
        <div>
            <span>Matching events</span>
            <p>Each match refreshes this card.</p>
        </div>
    </div>
    <div class="event-local-draft">
        <?= $this->Form->control('notes', [
            'id' => 'event-notes-' . Text::slug($label),
            'label' => 'Local notes',
            'placeholder' => 'A draft stays with its card…',
            'value' => $notes,
            'x-interact:model' => 'notes',
        ]) ?>
        <p class="quiet">Keep a draft here while events arrive.</p>
    </div>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
