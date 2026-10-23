<?php
declare(strict_types=1);

namespace Interact\Streaming;

use Cake\Error\ExceptionTrap;
use Cake\Http\CallbackStream;
use Cake\Http\Response;
use Interact\Http\FailureResponder;
use Interact\Runtime\PreparedUpdate;
use LengthException;
use Throwable;

/**
 * Executes an authorized action during native response emission using standard SSE framing.
 *
 * Middleware selects headers first. After emission begins, failures become safe terminal
 * frames and retain CakePHP's configured exception logging instead of rendering an error page.
 */
class SseResponse
{
    /**
     * Allow applications to replace the failure boundary and CakePHP exception logger.
     */
    public function __construct(
        protected readonly FailureResponder $failures = new FailureResponder(),
        protected readonly ExceptionTrap $exceptions = new ExceptionTrap(),
    ) {
    }

    /**
     * Select a non-seekable body without executing the action before headers are emitted.
     */
    public function create(PreparedUpdate $update, RequestContext $context, Response $response): Response
    {
        $body = new CallbackStream(function () use ($update, $context): void {
            $send = fn(array $message) => $this->write(['version' => 1, 'id' => $update->id, ...$message]);
            try {
                $context->release();
                $result = $update->run($send);
                $send([
                    'type' => 'result',
                    'status' => $result->errors === [] ? 200 : 422,
                    'payload' => $result->payload(),
                ]);
            } catch (Throwable $throwable) {
                $this->exceptions->logException($throwable, $context->request);
                $failure = $this->failures->fromException($throwable);
                $send([
                    'type' => 'failure',
                    'status' => $failure->getStatusCode(),
                    'payload' => json_decode((string)$failure->getBody(), flags: JSON_THROW_ON_ERROR),
                ]);
            }
        });

        return $response->withStatus(200)
            ->withHeader('Interact', 'true')
            ->withHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Accel-Buffering', 'no')
            ->withoutHeader('Content-Length')
            ->withBody($body);
    }

    /**
     * Flush complete standard SSE messages, keeping JSON newlines inside the data field.
     *
     * @param array<string, mixed> $message Versioned island-scoped update or terminal result.
     * @throws \LengthException When the encoded JSON data exceeds one MiB.
     */
    protected function write(array $message): void
    {
        $json = json_encode($message, JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        if (strlen($json) > 1_048_576) {
            throw new LengthException('Stream message exceeds the 1 MiB JSON data limit.');
        }

        echo 'event: interact' . "\n" . 'data: ' . $json . "\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
