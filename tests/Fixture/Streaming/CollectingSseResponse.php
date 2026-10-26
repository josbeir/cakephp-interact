<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Streaming;

use Interact\Streaming\SseResponse;

/**
 * Collects emitted response frames without writing or flushing PHPUnit's output buffers.
 */
class CollectingSseResponse extends SseResponse
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    /**
     * Observe semantic frames at the HTTP writer boundary.
     *
     * @param array<string, mixed> $message Versioned server frame.
     */
    protected function write(array $message): void
    {
        $this->messages[] = $message;
    }
}
