<?php
declare(strict_types=1);
/** @var int $count */
?>
<p aria-live="polite">Count: <?= (string)$count ?></p>
<button type="button" @click="$server.call('increment')" :disabled="$server.busy">Add one</button>
