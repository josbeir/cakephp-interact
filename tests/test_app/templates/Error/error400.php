<?php
declare(strict_types=1);
use function Cake\Core\h;
/**
 * @var \Cake\View\View $this
 * @var string $message
 */
$this->disableAutoLayout();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Request rejected</title></head>
<body><h1>Request rejected</h1><p><?= (string)h($message) ?></p></body>
</html>
