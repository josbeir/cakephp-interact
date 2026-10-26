<?php
declare(strict_types=1);

/**
 * @var \Interact\View\TemplateView $this
 * @var int $articleId
 * @var array<int, string> $articles
 */
?>
<section class="event-panel" aria-label="Dynamic event scope">
    <div class="demo-card event-scope">
        <div>
            <p class="eyebrow">02 / Choose a scope</p>
            <h2>Point a listener at a record</h2>
            <p class="card-description">Choose article 2 to make both cards listen to the same event. The selected card keeps its identity and notes.</p>
        </div>
        <?= $this->Form->control('articleId', [
            'label' => 'Watched article',
            'options' => $articles,
            'value' => $articleId,
            'x-interact:model.change' => 'articleId',
        ]) ?>
    </div>
    <div class="events-receivers">
        <?= $this->Interact->render(
            'Article/EventSummary',
            props: ['articleId' => $articleId, 'label' => 'Selected article'],
            key: 'selected-summary',
        ) ?>
        <?= $this->Interact->render(
            'Article/EventSummary',
            props: ['articleId' => 2, 'label' => 'Article 2'],
            key: 'second-summary',
        ) ?>
    </div>
</section>
