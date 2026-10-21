<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var array{title: string, body: string, published: bool, topics: list<string>, comments: list<array{body: string, approved: bool}>} $data
 * @var bool $saved
 * @var array<string, list<string>> $errors
 */
?>
<form id="native-draft" x-interact:model="data" x-interact:submit="$server.call('save')">
    <label for="native-title">Title</label>
    <input id="native-title" name="title" required maxlength="120">
    <label><input type="checkbox" name="published"> Published</label>
    <fieldset>
        <legend>Topics</legend>
        <label><input type="checkbox" name="topics[]" value="cakephp"> CakePHP</label>
        <label><input type="checkbox" name="topics[]" value="php"> PHP</label>
    </fieldset>
    <?php foreach ($data['comments'] as $index => $comment): ?>
        <label for="native-comment-<?= (int)$index ?>">Comment <?= (int)$index + 1 ?> text</label>
        <input id="native-comment-<?= (int)$index ?>" name="comments[<?= (int)$index ?>][body]">
        <label>
            <input type="checkbox" name="comments[<?= (int)$index ?>][approved]">
            Approve comment <?= (int)$index + 1 ?>
        </label>
        <button type="button" @click="$server.call('removeComment', <?= (int)$index ?>)">
            Remove comment <?= (int)$index + 1 ?>
        </button>
    <?php endforeach; ?>
    <button type="button" @click="$server.call('addComment')">Add comment</button>
    <?php foreach ($errors as $messages): ?>
        <?php foreach ($messages as $message): ?>
            <p role="alert"><?= (string)h($message) ?></p>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?= $this->Interact->render('counter', ['label' => 'Nested counter'], key: 'native-counter') ?>
    <button type="submit">Save article</button>
</form>
<label for="native-external">Body</label>
<textarea id="native-external" name="body" form="native-draft"></textarea>
<?php if ($saved): ?>
    <p role="status" x-show="!$server.dirty">Article saved.</p>
<?php endif; ?>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
