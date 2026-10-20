<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var string $draft
 * @var list<array{id: int, text: string, done: bool}> $tasks
 * @var int $completed
 * @var array<string, list<string>> $errors
 */
?>
<section class="demo-card" aria-label="Task board">
    <div class="card-heading"><div><p class="eyebrow">03 / Dynamic collections</p><h2>A little list of things</h2></div><span class="capability">Keyed rows</span></div>
    <p class="card-description">Add a task, mark it done, or remove it. Each row keeps its identity as the collection changes.</p>
    <div class="task-progress"><span><?= (string)$completed ?> of <?= (string)count($tasks) ?> completed</span><progress value="<?= (string)$completed ?>" max="<?= (string)max(1, count($tasks)) ?>" aria-label="Task completion"></progress></div>
    <ul class="task-list">
        <?php foreach ($tasks as $task): ?>
            <li id="demo-task-<?= (string)$task['id'] ?>" class="task-row <?= $task['done'] ? 'is-complete' : '' ?>">
                <button type="button" class="task-toggle" @click="$server.call('toggle', <?= (string)$task['id'] ?>)" :disabled="$server.busy" aria-pressed="<?= $task['done'] ? 'true' : 'false' ?>" aria-label="Complete <?= (string)h($task['text']) ?>"><span aria-hidden="true"><?= $task['done'] ? '✓' : '' ?></span></button>
                <span class="task-text"><?= (string)h($task['text']) ?></span>
                <button type="button" class="task-remove" @click="$server.call('remove', <?= (string)$task['id'] ?>)" :disabled="$server.busy" aria-label="Remove <?= (string)h($task['text']) ?>"><span aria-hidden="true">×</span></button>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($tasks === []): ?>
        <p class="empty-state">A clean slate. Add your first task below.</p>
    <?php endif; ?>
    <form data-interact-submit="add">
        <label>Add a task<input value="<?= (string)h($draft) ?>" data-interact-model="draft" maxlength="80" placeholder="What would you like to try?" <?= isset($errors['draft']) ? 'aria-invalid="true"' : '' ?>></label>
        <?php foreach ($errors['draft'] ?? [] as $error): ?>
            <p role="alert" class="field-error"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <div class="form-footer"><button type="submit" class="button-primary" :disabled="$server.busy">Add task <span aria-hidden="true">+</span></button><span class="quiet">Up to 12 tasks · Resets on reload</span></div>
    </form>
    <p class="request-error" role="alert" x-text="$server.error"></p>
</section>
