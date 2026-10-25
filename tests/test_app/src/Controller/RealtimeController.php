<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Cake\Routing\Attribute\Get;
use Cake\Routing\Attribute\Post;
use Cake\Routing\Router;
use Interact\Http\UpdateHandler;
use Interact\Realtime\SubscriptionHandler;

/**
 * Hosts the self-contained realtime example and explicit demo identity changes.
 *
 * The ordinary preview supports persistent chat without a hub.
 * Test-only identity switching never belongs in production applications.
 */
class RealtimeController extends AppController
{
    /**
     * Render the chat with optional hub setup instructions.
     */
    #[Get('/realtime', name: 'demo:realtime')]
    public function index(): void
    {
        $this->set('ready', Configure::read('Interact.realtime.provider') === 'mercure');
    }

    /**
     * Exercise application-owned endpoints in a non-default middleware scope.
     */
    #[Get('/realtime/scoped', name: 'demo:realtime:scoped')]
    public function scoped(): void
    {
        $this->viewBuilder()->addHelper('Interact', [
            'className' => 'Interact.Interact',
            'scope' => 'realtime-demo',
            'endpoint' => Router::url(['_name' => 'demo:realtime:update']),
            'subscriptionEndpoint' => Router::url(['_name' => 'demo:realtime:subscribe']),
        ]);
        $this->viewBuilder()->setTemplate('index');
        $this->index();
    }

    /**
     * Reuse ordinary update processing inside the application's scoped route.
     */
    #[Post('/realtime/update', name: 'demo:realtime:update', defaults: ['interactScope' => 'realtime-demo'])]
    public function update(UpdateHandler $handler): Response
    {
        return $handler->handle($this->getRequest(), $this->getResponse());
    }

    /**
     * Reauthorize subscription leases under the same current middleware scope.
     */
    #[Post('/realtime/subscribe', name: 'demo:realtime:subscribe', defaults: ['interactScope' => 'realtime-demo'])]
    public function subscribe(SubscriptionHandler $handler): Response
    {
        return $handler->handle($this->getRequest(), $this->getResponse());
    }

    /**
     * Exercise private topics using two deterministic demo identities.
     */
    #[Post('/demo/realtime-user/{userId}', name: 'demo:realtime-user', pass: ['userId'])]
    public function user(string $userId): Response
    {
        if (!in_array($userId, ['1', '2'], true)) {
            throw new BadRequestException('Unknown demo user.');
        }

        $this->request->getSession()->write('demo.userId', (int)$userId);
        $this->disableAutoRender();

        return $this->response->withType('application/json')->withStringBody('{"ok":true}');
    }
}
