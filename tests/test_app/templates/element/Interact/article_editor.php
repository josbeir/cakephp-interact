<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var string $title
 * @var int $articleId
 * @var bool $saved
 * @var array<string, list<string>> $errors
 */
?>
<article aria-label="Article <?= (string)h((string)$articleId) ?>">
    <form data-interact-submit="save">
        <label>
            Article title
            <input value="<?= (string)h($title) ?>" data-interact-model="title" <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
        </label>
        <?php foreach ($errors['title'] ?? [] as $error): ?>
            <p role="alert"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <button type="submit" :disabled="$server.busy">Save article</button>
        <?php if ($saved): ?>
            <p role="status">Saved.</p>
        <?php endif; ?>
        <p role="alert" x-text="$server.error"></p>
    </form>
</article>
