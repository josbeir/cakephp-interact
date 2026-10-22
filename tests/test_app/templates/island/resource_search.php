<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $controlPrefix
 * @var string $notes
 * @var string $search
 * @var string $category
 * @var string $level
 * @var string $sort
 * @var int $page
 * @var int $limit
 * @var \Cake\Datasource\Paging\PaginatedInterface<int, \Cake\ORM\Entity> $resources
 * @var int $total
 * @var int $librarySize
 * @var array<string, string> $categories
 * @var array<string, string> $levels
 */
$this->Paginator->setTemplates([
    'first' => '<a class="button" href="{{url}}">{{text}}</a>',
    'last' => '<a class="button" href="{{url}}">{{text}}</a>',
    'prevActive' => '<a class="button" rel="prev" href="{{url}}">{{text}}</a>',
    'nextActive' => '<a class="button" rel="next" href="{{url}}">{{text}}</a>',
    'prevDisabled' => '<span class="button" aria-disabled="true">{{text}}</span>',
    'nextDisabled' => '<span class="button" aria-disabled="true">{{text}}</span>',
]);
?>
<section class="demo-card resource-library" aria-label="Resource library">
    <div class="card-heading"><div><p class="eyebrow">Live search / <?= number_format($librarySize) ?> sample resources</p><h2>A library worth exploring</h2></div><span class="capability">Search + filter + sort</span></div>
    <p class="card-description">Search titles, descriptions, and topics. Combine filters to narrow the list, or change the reading order to find something quick.</p>
    <?= $this->Form->create(null, ['url' => false, 'idPrefix' => $controlPrefix, 'x-interact:submit' => '$server.sync()']) ?>
        <div class="library-query">
            <?= $this->Form->control('search', [
                'type' => 'search',
                'label' => 'Search resources',
                'value' => $search,
                'maxlength' => 200,
                'placeholder' => 'Search 1,200 resources…',
                'x-interact:model.live.debounce.300ms' => 'search',
                'aria-describedby' => $controlPrefix . '-hint',
                'autocomplete' => 'off',
            ]) ?>
        </div>
        <p class="field-hint" id="<?= (string)h($controlPrefix) ?>-hint">Results update as you type. Multiple words match across the title and description.</p>
        <div class="library-filters">
            <?= $this->Form->control('category', [
                'label' => 'Topic',
                'options' => $categories,
                'empty' => 'All topics',
                'value' => $category,
                'x-interact:model.change' => 'category',
            ]) ?>
            <?= $this->Form->control('level', [
                'label' => 'Difficulty',
                'options' => $levels,
                'empty' => 'All levels',
                'value' => $level,
                'x-interact:model.change' => 'level',
            ]) ?>
            <?= $this->Form->control('sort', [
                'label' => 'Sort by',
                'options' => ['title' => 'Title: A–Z', 'title-desc' => 'Title: Z–A', 'shortest' => 'Shortest read first', 'longest' => 'Longest read first'],
                'value' => $sort,
                'x-interact:model.change' => 'sort',
            ]) ?>
            <?= $this->Form->control('limit', [
                'label' => 'Per page',
                'options' => [10 => '10 results', 20 => '20 results', 50 => '50 results'],
                'value' => $limit,
                'x-interact:model.change' => 'limit',
            ]) ?>
        </div>
        <details>
            <summary>Reading notes</summary>
            <?= $this->Form->control('notes', [
                'type' => 'textarea',
                'label' => 'Reading notes',
                'value' => $notes,
                'x-interact:model' => 'notes',
            ]) ?>
        </details>
    <?= $this->Form->end() ?>
    <div class="library-summary"><p role="status" aria-live="polite" aria-atomic="true">Showing <?= number_format((int)$this->Paginator->param('start')) ?>–<?= number_format((int)$this->Paginator->param('end')) ?> of <strong><?= number_format($total) ?></strong> results</p><span class="quiet" x-show="$server.loading(null)" x-cloak>Searching…</span><button class="button-text" type="button" @click="$server.call('clear')" :disabled="$server.busy">Clear filters</button></div>
    <?php if ($total === 0): ?>
        <div class="empty-state library-empty"><h3>No resources found</h3><p>Try fewer words, choose another topic, or clear the filters to explore the full library.</p><button type="button" @click="$server.call('clear')" :disabled="$server.busy">Show all resources</button></div>
    <?php else: ?>
        <div class="search-results">
            <table class="resource-table">
                <caption class="sr-only">Resource search results</caption>
                <thead><tr><th scope="col"><?= $this->Paginator->sort('title', 'Resource') ?></th><th scope="col">Topic</th><th scope="col">Difficulty</th><th scope="col" class="reading-time">Read time</th></tr></thead>
                <tbody>
                <?php foreach ($resources as $resource): ?>
                    <tr id="<?= (string)h($controlPrefix) ?>-resource-<?= (string)h((string)$resource->get('id')) ?>">
                        <td><h3><?= (string)h((string)$resource->get('title')) ?></h3><p class="resource-description"><?= (string)h((string)$resource->get('summary')) ?></p></td>
                        <td data-column="Topic"><span class="topic-tag"><?= (string)h($categories[(string)$resource->get('category')] ?? '') ?></span></td>
                        <td data-column="Difficulty"><span class="resource-level"><?= (string)h($levels[(string)$resource->get('level')] ?? '') ?></span></td>
                        <td data-column="Read time" class="reading-time"><?= (string)h((string)$resource->get('minutes')) ?> min</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <div class="library-pagination">
        <p class="quiet"><?= $this->Paginator->counter('Page {{page}} of {{pages}}') ?></p>
        <nav class="button-row" aria-label="Resource pages">
            <?= $this->Paginator->first('First') ?>
            <?= $this->Paginator->prev('Previous') ?>
            <?= $this->Paginator->next('Next') ?>
            <?= $this->Paginator->last('Last') ?>
        </nav>
    </div>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
