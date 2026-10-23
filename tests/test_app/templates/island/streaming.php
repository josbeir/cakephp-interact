<?php
declare(strict_types=1);
use function Cake\Core\h;
/** @var string $answer */
?>
<pre x-interact:stream="answer"><?=(string)h($answer) ?></pre>
<div x-interact:stream.html="results"></div>
