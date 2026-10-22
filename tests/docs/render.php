<?php
declare(strict_types=1);

use Interact\Loading\Options;
use Interact\Runtime\Values;
use Interact\Test\Fixture\DocumentationRuntime;

require dirname(__DIR__) . '/bootstrap.php';

// Stable isolated binding allows consecutive browser-verification CLI requests.
session_id('interact-documentation-browser');
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$examples = new DocumentationRuntime();
$input = (string)stream_get_contents(STDIN);
if ($input !== '') {
    /** @var mixed $payload */
    $payload = json_decode($input, true, flags: JSON_THROW_ON_ERROR);
    $result = $examples->runtime->update(Values::map($payload), $examples->context);
    echo json_encode($result->payload(), JSON_THROW_ON_ERROR);
} else {
    $alias = $argv[1] ?? 'Article/Form';
    echo $examples->runtime->mount($alias, [], $alias, $examples->context, new Options($argv[2] ?? 'eager'))->html;
}
