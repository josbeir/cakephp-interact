<?php
declare(strict_types=1);
/**
 * @var \Interact\View\TemplateView $this
 * @var string $value
 * @var bool $templateDump
 */
use function Cake\Core\h;
use function Cake\Error\debug;
?>
<section aria-label="Debug probe">
    <label>Probe value <input x-interact:model="value" value="<?= (string)h($value) ?>"></label>
    <output><?= (string)h($value) ?></output>
    <button type="button" @click="$server.call('inspect')">Inspect island</button>
    <button type="button" @click="$server.call('inspectStream')">Inspect stream</button>
    <button type="button" @click="$server.call('inspectOnly')">Inspect without rendering</button>
    <button type="button" @click="$server.call('inspect', 'validation')">Inspect validation</button>
    <button type="button" @click="$server.call('inspect', 'failure')">Inspect failure</button>
    <button type="button" @click="$server.call('leave')">Inspect redirect</button>
    <pre x-interact:stream="progress"></pre>
    <?php if ($templateDump): ?>
        <?php debug('template output', true); ?>
    <?php endif; ?>
</section>
