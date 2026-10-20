<?php
declare(strict_types=1);

namespace Interact\Http;

use Cake\Http\Exception\HttpException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\Values;
use Interact\Security\InvalidSnapshotException;
use Interact\View\TemplateView;
use InvalidArgumentException;

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
    public function __construct(protected readonly Factory $factory, protected readonly FailureResponder $failures)
    {
    }

    /**
     * Validate a POST request and return an uncached, versioned JSON response.
     */
    public function handle(ServerRequest $request, Response $response): Response
    {
        try {
            $request->allowMethod(['post']);
            /** @var mixed $body */
            $body = $request->getData();
            if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/form-data')) {
                /** @var mixed $payload */
                $payload = $request->getData('payload');
                if (!is_string($payload) || strlen($payload) > 1_048_576) {
                    throw new InvalidArgumentException('Invalid upload envelope.');
                }

                /** @var mixed $body */
                $body = json_decode($payload, true);
            } elseif (strlen((string)$request->getBody()) > 1_048_576) {
                throw new InvalidArgumentException('Invalid island request size.');
            }

            if (!is_array($body) || ($body['version'] ?? null) !== 1) {
                throw new InvalidArgumentException('Invalid island request.');
            }

            /** @var mixed $scope */
            $scope = $request->getParam('interactScope', 'default');
            if (!is_string($scope) || $scope === '') {
                throw new InvalidArgumentException('Invalid island scope.');
            }

            $context = new IslandContext($request, $scope, $request->getUri()->getPath());
            $runtime = $this->factory->create(new TemplateView($request, $response), $context);
            $result = $runtime->update(Values::map($body), $context);

            return $response->withStatus($result->errors === [] ? 200 : 422)
                ->withType('application/json')
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
