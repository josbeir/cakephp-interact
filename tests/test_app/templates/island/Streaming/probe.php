<?php
declare(strict_types=1);
use function Cake\Core\h;
/** @var string $answer */
?>
<div
    x-data="{ address: '' }"
    x-interact:on:gate="address = $event.detail.address"
    :data-gate-address="address"
>
    <button type="button" @click="$server.call('hold')" :disabled="$server.busy">Start gated stream</button>
    <pre x-interact:stream="answer"><?= (string)h($answer) ?></pre>
    <ul x-interact:stream.html="rows"></ul>
</div>
