<?php
declare(strict_types=1);
use function Cake\Core\h;
/** @var string $answer */
?>
<div x-data="{ address: '' }" @interact:stream.window="$event.target === $el.closest('[data-interact-id]') && (address = $event.detail.detail.address)" :data-gate-address="address">
    <button type="button" @click="$server.call('hold')" :disabled="$server.busy">Start gated stream</button>
    <pre x-interact:stream="answer"><?= (string)h($answer) ?></pre>
    <ul x-interact:stream.html="rows"></ul>
</div>
