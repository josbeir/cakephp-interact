<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use RuntimeException;

/**
 * Pauses an action at a controlled socket boundary so browser tests can observe a live response.
 *
 * The listener binds only to loopback, chooses a free port, and has a bounded timeout.
 * It owns no application records or filesystem state.
 */
class StreamingProbeIsland extends Island
{
    #[State]
    public string $answer = '';

    /**
     * Wait for the test's explicit release after emitting visible content and progress.
     */
    #[Action(stream: true)]
    public function hold(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        if ($listener === false) {
            throw new RuntimeException('Unable to create streaming test gate.');
        }

        try {
            $this->stream('answer', 'Partial <text> €', replace: true);
            $this->streamElement('rows', 'streaming_probe_row', ['text' => '<escaped>']);
            $this->streamEvent('gate', ['address' => stream_socket_get_name($listener, false)]);
            $connection = stream_socket_accept($listener, 15);
            if ($connection === false) {
                throw new RuntimeException('Streaming gate was not released.');
            }

            fclose($connection);
            $this->answer = 'Complete';
        } finally {
            fclose($listener);
        }
    }
}
