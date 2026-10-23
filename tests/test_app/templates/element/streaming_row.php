<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var int $index
 * @var string $title
 * @var string $description
 */
?>
<li class="stream-result">
    <span class="stream-result-number" aria-hidden="true"><?= sprintf('%02d', $index) ?></span>
    <div>
        <h3><?= (string)h($title) ?></h3>
        <p><?= (string)h($description) ?></p>
    </div>
    <span class="stream-result-check" aria-label="Received">✓</span>
</li>
