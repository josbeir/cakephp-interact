<?php
declare(strict_types=1);

$path = $argv[1] ?? 'build/clover.xml';
if (!is_file($path)) {
    fwrite(STDERR, "Coverage report is missing.\n");
    exit(1);
}
$document = new DOMDocument();
if (!$document->load($path, LIBXML_NONET)) {
    fwrite(STDERR, "Coverage report is invalid.\n");
    exit(1);
}
/** @var DOMNodeList<DOMElement>|false $nodes */
$nodes = new DOMXPath($document)->query('/coverage/project/metrics');
$metrics = $nodes === false ? null : $nodes->item(0);
if (!$metrics instanceof DOMElement) {
    fwrite(STDERR, "Coverage report has no project metrics.\n");
    exit(1);
}
$total = (int)$metrics->getAttribute('statements');
$covered = (int)$metrics->getAttribute('coveredstatements');
if ($total <= 0 || $covered < 0 || $covered > $total || $covered * 100 < $total * 95) {
    fwrite(STDERR, "Line coverage must be at least 95% with executable source measured.\n");
    exit(1);
}
fwrite(STDOUT, sprintf(
    "Line coverage: %.2f%% (%d/%d), 95%% gate passed.\n",
    ($covered / $total) * 100,
    $covered,
    $total,
));
