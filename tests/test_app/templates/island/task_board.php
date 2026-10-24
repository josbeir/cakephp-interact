<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $draft
 * @var list<array{id: int, text: string, done: bool}> $tasks
 * @var int $completed
 * @var array<string, list<string>> $errors
 */
?>
<section class="demo-card" aria-label="Task board">
    <div class="card-heading"><div><p class="eyebrow">03 / Dynamic collections</p><h2>Task list</h2></div><span class="capability">Keyed rows</span></div>
    <p class="card-description">Declared actions update a state array. Stable row IDs preserve DOM identity as tasks are added, toggled, or removed.</p>
    <div class="task-progress"><span><?= (string)$completed ?> of <?= (string)count($tasks) ?> completed</span><progress value="<?= (string)$completed ?>" max="<?= (string)max(1, count($tasks)) ?>" aria-label="Task completion"></progress></div>
    <ul class="task-list">
        <?php foreach ($tasks as $task): ?>
            <li id="demo-task-<?= (string)$task['id'] ?>" :class="{ 'task-pending': $server.loading('remove', <?= (string)$task['id'] ?>) }" class="task-row <?= $task['done'] ? 'is-complete' : '' ?>">
                <button type="button" class="task-toggle" @click="$server.call('toggle', <?= (string)$task['id'] ?>)" :disabled="$server.pending('toggle', <?= (string)$task['id'] ?>)" aria-pressed="<?= $task['done'] ? 'true' : 'false' ?>" aria-label="Complete <?= (string)h($task['text']) ?>"><span aria-hidden="true"><?= $task['done'] ? '✓' : '' ?></span></button>
                <span class="task-text"><?= (string)h($task['text']) ?></span>
                <button type="button" class="task-remove" @click="$server.call('remove', <?= (string)$task['id'] ?>)" :disabled="$server.pending('remove', <?= (string)$task['id'] ?>)" aria-label="Remove <?= (string)h($task['text']) ?>"><span aria-hidden="true">×</span></button>
                <span class="quiet task-feedback" x-show="$server.loading('remove', <?= (string)$task['id'] ?>)" x-cloak role="status">Removing…</span>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($tasks === []): ?>
        <p class="empty-state">A clean slate. Add your first task below.</p>
    <?php endif; ?>
    <?= $this->Form->create(null, ['url' => false, 'id' => 'task-form', 'idPrefix' => 'TaskBoard', 'x-interact:submit' => '$server.call("add")']) ?>
        <?= $this->Form->control('draft', [
            'type' => 'text',
            'label' => 'Add a task',
            'value' => $draft,
            'maxlength' => 80,
            'placeholder' => 'What would you like to try?',
            'x-interact:model' => 'draft',
            'aria-invalid' => isset($errors['draft']) ? 'true' : null,
        ]) ?>
        <?php foreach ($errors['draft'] ?? [] as $error): ?>
            <p role="alert" class="field-error"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <div class="form-footer"><?= $this->Form->button('Add task <span aria-hidden="true">+</span>', ['class' => 'button-primary', 'escape' => false]) ?><span class="quiet">Up to 12 tasks · Resets on reload</span></div>
    <?= $this->Form->end() ?>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
