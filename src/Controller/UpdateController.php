<?php
declare(strict_types=1);

namespace Interact\Controller;

use Cake\Controller\Controller;
use Cake\Http\Exception\HttpException;
use Cake\Http\Response;
use Interact\IslandContext;
use Interact\Runtime\Factory;
use Interact\Runtime\Values;
use Interact\Security\InvalidSnapshotException;
use Interact\View\TemplateView;
use InvalidArgumentException;

/**
 * Processes versioned AJAX updates after the application's normal middleware stack.
 *
 * The factory creates a fresh rendering runtime for the verified island snapshot.
 * Validation returns renderable feedback; malformed state and expected authorization
 * failures return uncached JSON without rerunning the original page controller.
 */
class UpdateController extends Controller
{
    /**
     * Return a versioned JSON result; never mix login/error documents into island markup.
     */
    public function update(Factory $factory): Response
    {
        $this->getRequest()->allowMethod(['post']);
        $this->disableAutoRender();

        $request = $this->getRequest();

        /** @var mixed $scope */
        $scope = $request->getParam('interactScope', 'default');
        /** @var mixed $body */
        $body = $request->getData();
        try {
            if (
                !is_array($body)
                || ($body['version'] ?? null) !== 1
                || strlen((string)$request->getBody()) > 1_048_576
            ) {
                throw new InvalidArgumentException('Invalid island request.');
            }

            $context = new IslandContext($request, (string)$scope, $request->getUri()->getPath());
            $runtime = $factory->create(new TemplateView($request), $context);
            $result = $runtime->update(Values::map($body), $context);

            return $this->json($result->payload(), $result->errors === [] ? 200 : 422);
        } catch (InvalidSnapshotException $exception) {
            return $this->json(['error' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 400);
        } catch (HttpException $exception) {
            return $this->json(['error' => $exception->getMessage()], (int)$exception->getCode());
        }
    }

    /**
     * Encode a result or expected application error as an uncached JSON response.
     *
     * @param array<string, mixed> $data A result or a safe application error.
     */
    protected function json(array $data, int $status): Response
    {
        return $this->getResponse()
            ->withStatus($status)
            ->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withStringBody(json_encode($data, JSON_THROW_ON_ERROR | JSON_HEX_TAG));
    }
}
