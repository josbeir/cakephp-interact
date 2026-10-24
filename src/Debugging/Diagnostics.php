<?php
declare(strict_types=1);

namespace Interact\Debugging;

use Cake\Core\Configure;
use Cake\Http\Response;
use Interact\Configuration;
use stdClass;
use Throwable;
use WeakMap;

/**
 * Selects development capture and decorates HTTP responses at the transport boundary.
 *
 * Unexpected exceptions retain their native identity and CakePHP logging path.
 * Weak exception keys carry output to the error listener without retaining requests.
 */
class Diagnostics
{
    /**
     * @var WeakMap<Throwable, OutputCapture>
     */
    protected WeakMap $failures;

    /**
     * Receive validated application configuration through the container.
     */
    public function __construct(protected readonly Configuration $configuration = new Configuration())
    {
        $this->failures = new WeakMap();
    }

    /**
     * Create isolated capture only while both application and plugin debugging allow it.
     */
    public function begin(): ?OutputCapture
    {
        return Configure::read('debug') === true && $this->configuration->debugEnabled
            ? new OutputCapture($this->configuration->debugViewer)
            : null;
    }

    /**
     * Preserve output for CakePHP's later exception rendering without wrapping the exception.
     */
    public function remember(Throwable $exception, ?OutputCapture $capture): void
    {
        if ($capture instanceof OutputCapture) {
            $this->failures[$exception] = $capture;
        }
    }

    /**
     * Add pending output to an existing JSON response without changing its status or headers.
     */
    public function response(Response $response, ?OutputCapture $capture): Response
    {
        $data = $capture?->take();
        if ($data === null || !Configure::read('debug')) {
            return $response;
        }

        /** @var mixed $payload */
        $payload = json_decode((string)$response->getBody(), flags: JSON_THROW_ON_ERROR);
        assert($payload instanceof stdClass, 'Diagnostic decoration requires a JSON response object.');
        $payload->diagnostics = $data;

        return $response->withStringBody(json_encode($payload, JSON_THROW_ON_ERROR | JSON_HEX_TAG));
    }

    /**
     * Consume diagnostics associated with an otherwise unchanged exception response.
     */
    public function failure(Throwable $exception, Response $response): Response
    {
        $capture = $this->failures[$exception] ?? null;
        unset($this->failures[$exception]);

        return $this->response($response, $capture);
    }
}
