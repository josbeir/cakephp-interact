<?php
declare(strict_types=1);

namespace Interact\Http;

use Cake\Http\Exception\HttpException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Interact\Debugging\Diagnostics;
use Interact\Debugging\OutputCapture;
use Interact\Runtime\Factory;
use Interact\Security\InvalidSnapshotException;
use Interact\Streaming\RequestContext;
use Interact\Streaming\SseResponse;
use Interact\View\TemplateView;
use InvalidArgumentException;
use Throwable;

/**
 * Handles island HTTP requests independently of their hosting controller.
 *
 * Applications can inject this service into their own actions. Middleware remains
 * authoritative, and unexpected exceptions retain CakePHP's logging workflow.
 */
class UpdateHandler
{
    /**
     * Receive the shared runtime factory and safe failure encoder through the container.
     */
    public function __construct(
        protected readonly Factory $factory,
        protected readonly FailureResponder $failures,
        protected readonly SseResponse $streams = new SseResponse(),
        protected readonly Diagnostics $diagnostics = new Diagnostics(),
    ) {
    }

    /**
     * Validate a POST request and return an uncached, versioned JSON response.
     */
    public function handle(ServerRequest $request, Response $response): Response
    {
        $capture = $this->diagnostics->begin();
        try {
            $execute = fn(): Response => $this->execute($request, $response, $capture);
            $result = $capture instanceof OutputCapture ? $capture->run($execute) : $execute();

            return $result->getHeaderLine('Content-Type') === 'application/json'
                ? $this->diagnostics->response($result, $capture)
                : $result;
        } catch (Throwable $throwable) {
            $this->diagnostics->remember($throwable, $capture);

            throw $throwable;
        }
    }

    /**
     * Execute the existing update contract within the selected diagnostic boundary.
     */
    protected function execute(ServerRequest $request, Response $response, ?OutputCapture $capture): Response
    {
        try {
            $request->allowMethod(['post']);
            $body = new UpdatePayload()->read($request);

            /** @var mixed $scope */
            $scope = $request->getParam('interactScope', 'default');
            if (!is_string($scope) || $scope === '') {
                throw new InvalidArgumentException('Invalid island scope.');
            }

            $context = new RequestContext($request, $scope, $request->getUri()->getPath());
            $runtime = $this->factory->create(new TemplateView($request, $response), $context);
            $update = $runtime->prepare($body, $context);
            if ($update->stream && $request->accepts('text/event-stream')) {
                return $this->streams->create($update, $context, $response, $capture);
            }

            $result = $update->run();

            return $response->withStatus($result->errors === [] ? 200 : 422)
                ->withType('application/json')
                ->withHeader('Interact', 'true')
                ->withHeader('Cache-Control', 'no-store')
                ->withStringBody(json_encode($result->payload(), JSON_THROW_ON_ERROR | JSON_HEX_TAG));
        } catch (InvalidSnapshotException|InvalidArgumentException|HttpException $exception) {
            if ($exception instanceof HttpException && $exception->getCode() >= 500) {
                throw $exception;
            }

            $failure = $this->failures->fromException($exception);
            foreach ($failure->getHeaders() as $name => $values) {
                $response = $response->withHeader($name, $values);
            }

            return $response->withStatus($failure->getStatusCode())->withBody($failure->getBody());
        }
    }
}
