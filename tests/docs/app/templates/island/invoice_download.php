<?php
declare(strict_types=1);
/**
 * templates/island/invoice_download.php
 *
 * @var \Interact\View\TemplateView $this
 * @var int $exports
 */
?>
<?= $this->Form->button('Download invoice', ['type' => 'button', '@click' => '$server.call("download")']) ?>
<p role="status">Requested <?= $exports ?> downloads.</p>
<p role="alert" x-text="$server.error ? $server.error.message : ''"></p>
