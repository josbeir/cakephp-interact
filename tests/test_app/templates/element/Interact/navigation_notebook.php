<?php
declare(strict_types=1);

use function Cake\Core\h;

/**
 * @var \Interact\View\TemplateView $this
 * @var string $edition
 * @var string $note
 * @var string $filter
 * @var int $saved
 */
?>
<p class="eyebrow">Persistent layout</p>
<h2>Layout notebook</h2>
<p class="quiet">Edition: <strong><?= (string)h($edition) ?></strong></p>
<p class="card-description">Type a draft and switch pages. This island stays mounted, including edits you have not sent to PHP.</p>
<?= $this->Form->create(null, ['x-interact:submit' => '$server.call("remember")']) ?>
    <?= $this->Form->control('note', ['label' => 'Notebook draft', 'value' => $note, 'x-interact:model' => 'note']) ?>
    <?= $this->Form->control('filter', ['label' => 'Notebook URL filter', 'value' => $filter, 'x-interact:model.change' => 'filter']) ?>
    <div class="button-row">
        <?= $this->Form->button('Remember draft', ['class' => 'button-primary']) ?>
        <button type="button" @click="$server.call('openDetails')">Open details through an action</button>
    </div>
<?= $this->Form->end() ?>
<p class="navigation-notebook-status" role="status">Remembered <strong><?= $saved ?></strong> times</p>
<button class="button-text" type="button" @click="$server.sync()">Refresh notebook</button>
