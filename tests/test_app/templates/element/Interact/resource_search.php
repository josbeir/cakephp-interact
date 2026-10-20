<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var string $search
 * @var string $category
 * @var string $level
 * @var string $sort
 * @var int $page
 * @var int $pageSize
 * @var \Cake\ORM\ResultSet<int, \Cake\ORM\Entity> $resources
 * @var int $total
 * @var int $librarySize
 * @var int $totalPages
 * @var int $first
 * @var int $last
 * @var array<string, string> $categories
 * @var array<string, string> $levels
 */
?>
<section class="demo-card resource-library" aria-label="Resource library">
    <div class="card-heading"><div><p class="eyebrow">Live search / <?= number_format($librarySize) ?> sample resources</p><h2>A library worth exploring</h2></div><span class="capability">Search + filter + sort</span></div>
    <p class="card-description">Search titles, descriptions, and topics. Combine filters to narrow the list, or change the reading order to find something quick.</p>
    <form @submit.prevent="$server.sync()">
        <label class="library-query">Search resources
            <input type="search" value="<?= (string)h($search) ?>" maxlength="200" placeholder="Search 1,200 resources…" data-interact-model="search" data-interact-update="input" data-interact-debounce="300" aria-describedby="search-hint" autocomplete="off">
        </label>
        <p class="field-hint" id="search-hint">Results update as you type. Multiple words match across the title and description.</p>
        <div class="library-filters">
            <label>Topic
                <select data-interact-model="category" data-interact-update="change">
                    <option value="">All topics</option>
                    <?php foreach ($categories as $value => $label): ?>
                        <option value="<?= (string)h($value) ?>" <?= $category === $value ? 'selected' : '' ?>><?= (string)h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Difficulty
                <select data-interact-model="level" data-interact-update="change">
                    <option value="">All levels</option>
                    <?php foreach ($levels as $value => $label): ?>
                        <option value="<?= (string)h($value) ?>" <?= $level === $value ? 'selected' : '' ?>><?= (string)h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Sort by
                <select data-interact-model="sort" data-interact-update="change">
                    <?php foreach (['title' => 'Title: A–Z', 'title-desc' => 'Title: Z–A', 'shortest' => 'Shortest read first', 'longest' => 'Longest read first'] as $value => $label): ?>
                        <option value="<?= (string)h($value) ?>" <?= $sort === $value ? 'selected' : '' ?>><?= (string)h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Per page
                <select data-interact-model="pageSize" data-interact-update="change">
                    <?php foreach ([10, 20, 50] as $size): ?>
                        <option value="<?= (string)$size ?>" <?= $pageSize === $size ? 'selected' : '' ?>><?= (string)$size ?> results</option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>
    </form>
    <div class="library-summary"><p role="status" aria-live="polite" aria-atomic="true">Showing <?= number_format($first) ?>–<?= number_format($last) ?> of <strong><?= number_format($total) ?></strong> results</p><span class="quiet" x-show="$server.busy" x-cloak>Searching…</span><button class="button-text" type="button" data-interact-click="clear" :disabled="$server.busy">Clear filters</button></div>
    <?php if ($total === 0): ?>
        <div class="empty-state library-empty"><h3>No resources found</h3><p>Try fewer words, choose another topic, or clear the filters to explore the full library.</p><button type="button" data-interact-click="clear" :disabled="$server.busy">Show all resources</button></div>
    <?php else: ?>
        <div class="search-results">
            <table class="resource-table">
                <caption class="sr-only">Resource search results</caption>
                <thead><tr><th scope="col">Resource</th><th scope="col">Topic</th><th scope="col">Difficulty</th><th scope="col" class="reading-time">Read time</th></tr></thead>
                <tbody>
                <?php foreach ($resources as $resource): ?>
                    <tr id="resource-<?= (string)h((string)$resource->get('id')) ?>">
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
    <div class="library-pagination"><p class="quiet">Page <?= number_format($page) ?> of <?= number_format($totalPages) ?></p><div class="button-row">
        <button type="button" @click="$server.call('goTo', 1)" :disabled="$server.busy || <?= $page <= 1 ? 'true' : 'false' ?>">First</button>
        <button type="button" @click="$server.call('goTo', <?= (string)($page - 1) ?>)" :disabled="$server.busy || <?= $page <= 1 ? 'true' : 'false' ?>">Previous</button>
        <button type="button" @click="$server.call('goTo', <?= (string)($page + 1) ?>)" :disabled="$server.busy || <?= $page >= $totalPages ? 'true' : 'false' ?>">Next <span aria-hidden="true">→</span></button>
        <button type="button" @click="$server.call('goTo', <?= (string)$totalPages ?>)" :disabled="$server.busy || <?= $page >= $totalPages ? 'true' : 'false' ?>">Last</button>
    </div></div>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
