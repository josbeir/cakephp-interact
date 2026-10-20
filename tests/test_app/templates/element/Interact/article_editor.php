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
<article class="article-editor" aria-label="Article <?= (string)h((string)$articleId) ?>">
    <div class="row-heading"><span class="row-id">Article / <?= str_pad((string)$articleId, 2, '0', STR_PAD_LEFT) ?></span><span class="draft-indicator" x-show="$server.dirty" x-cloak>Unsaved changes</span></div>
    <form data-interact-submit="save">
        <label>
            Article title
            <input value="<?= (string)h($title) ?>" data-interact-model="title" <?= isset($errors['title']) ? 'aria-invalid="true"' : '' ?>>
        </label>
        <?php foreach ($errors['title'] ?? [] as $error): ?>
            <p role="alert" class="field-error"><?= (string)h($error) ?></p>
        <?php endforeach; ?>
        <div class="form-footer"><button type="submit" :disabled="$server.busy">Save article</button><span class="quiet" x-show="$server.busy" x-cloak>Saving…</span>
        <?php if ($saved): ?>
            <p class="success-message" role="status" x-show="!$server.dirty">Saved.</p>
        <?php endif; ?>
        </div>
        <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
    </form>
</article>
