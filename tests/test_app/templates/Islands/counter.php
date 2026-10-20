<?php
declare(strict_types=1);
use function Cake\Core\h;

/**
 * @var int $count
 * @var string $heading
 */
?>
<h2><?= (string)h($heading) ?></h2>
<p><?= (string)h((string)$count) ?></p>
<button type="button" @click="$server.call('increment')">Increment</button>
