<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Cake\Http\Exception\HttpException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Interact\Discovery\Registry;
use Interact\Http\UpdatePayload;
use Interact\IslandContext;
use Interact\Runtime\Hydrator;
use Interact\Runtime\Values;
use Interact\Security\InvalidSnapshotException;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Reauthorizes subscription-only identities without running application behavior or views.
 *
 * Batches share one provider grant while retaining per-island failures. Browser-supplied
 * topic permissions and normal snapshots are never accepted as subscription proofs.
 */
class SubscriptionHandler
{
    /**
     * Inject declaration resolution, subscription signing, and the configured provider.
     */
    public function __construct(
        protected readonly Registry $registry,
        protected readonly LeaseCodec $leases,
        protected readonly SubscriptionAuthorizerInterface $authorizer,
        protected readonly Subscriptions $subscriptions,
    ) {}

    /**
     * Return renewed leases and one provider grant after current application authorization.
     */
    public function handle(ServerRequest $request, Response $response): Response
    {
        $request->allowMethod(['post']);
        $payload = new UpdatePayload()->read($request);
        $tokens = Values::map($payload['leases'] ?? null);
        /** @var mixed $scope */
        $scope = $request->getParam('interactScope', 'default');
        if ($tokens === [] || count($tokens) > 64 || !is_string($scope) || $scope === '') {
            throw new InvalidArgumentException('Invalid subscription request.');
        }

        $results = [];
        $topics = [];
        /** @var mixed $token */
        foreach ($tokens as $id => $token) {
            try {
                if (!is_string($token)) {
                    throw new InvalidArgumentException('Invalid subscription lease.');
                }

                $result = $this->renew($token, new IslandContext($request, $scope, ''));
                if ($result['id'] !== $id) {
                    throw new InvalidArgumentException('Subscription lease identity mismatch.');
                }

                $results[$id] = $result;
                foreach ($result['subscriptions'] as $subscription) {
                    $topics[$subscription['topic']] = $subscription['topic'];
                }
            } catch (HttpException|InvalidSnapshotException|InvalidArgumentException $exception) {
                if ($exception instanceof HttpException && $exception->getCode() >= 500) {
                    throw $exception;
                }

                $results[$id] = ['error' => 'Subscription authorization failed.'];
            }
        }

        $grant = $topics === [] ? null : $this->authorizer->authorize(array_values($topics), $this->leases->expires());

        return $response->withType('application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Interact', 'true')
            ->withStringBody(json_encode([
                'version' => 1,
                'islands' => (object)$results,
                'grant' => $grant?->data(),
            ], JSON_THROW_ON_ERROR | JSON_HEX_TAG));
    }

    /**
     * Restore accepted state and check current identity without invoking actions or hooks.
     *
     * @return array{id: string, lease: string, subscriptions: list<array{topic: string, mode: string, event: string|null, private: bool}>}
     */
    protected function renew(#[SensitiveParameter] string $token, IslandContext $context): array
    {
        $data = $this->leases->open($token, $context->binding(), $context->scope);
        $identity = new LeaseIdentity($data);
        $definition = $this->registry->definition($identity->alias);
        $island = $this->registry->create($definition, $context->request);
        new Hydrator()->restore($island, $definition, $identity->state);
        $context = new IslandContext($context->request, $context->scope, $identity->endpoint);
        $island->authorize($context);

        return [
            'id' => $identity->id,
            'lease' => $this->leases->seal($data, $context->binding(), $context->scope),
            'subscriptions' => $this->subscriptions->declarations($island, $context),
        ];
    }
}
