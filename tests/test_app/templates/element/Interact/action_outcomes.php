<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var \Cake\Datasource\EntityInterface $article
 * @var string $title
 * @var int $exports
 */
?>
<section aria-label="Action outcomes">
    <div class="demo-columns">
        <div class="demo-card">
            <h2>Create an article</h2>
            <p class="card-description">Validation keeps the form open. A successful save visits the new article.</p>
            <?= $this->Form->create($article, ['url' => false, 'x-interact:submit' => '$server.call("save")']) ?>
                <?= $this->Form->control('title', [
                    'type' => 'text',
                    'value' => $title,
                    'x-interact:model' => 'title',
                    'required' => false,
                ]) ?>
                <div class="button-row form-footer">
                    <?= $this->Form->button('Save and visit article') ?>
                    <button type="button" @click="$server.call('save', true)">Save and replace history</button>
                </div>
            <?= $this->Form->end() ?>
        </div>
        <aside class="demo-card">
            <h2>Download an invoice</h2>
            <p class="card-description">Your title draft stays here while the browser downloads the file.</p>
            <button type="button" @click="$server.call('download')">Download invoice</button>
            <p class="card-description" role="status">Requested <?= $exports ?> downloads.</p>
        </aside>
    </div>
    <p class="request-error" role="alert" x-text="$server.error ? $server.error.message : ''"></p>
</section>
