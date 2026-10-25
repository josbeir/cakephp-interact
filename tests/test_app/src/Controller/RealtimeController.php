<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Core\Configure;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Cake\Routing\Attribute\Get;
use Cake\Routing\Attribute\Post;

/**
 * Hosts the self-contained realtime example and explicit demo identity changes.
 *
 * The ordinary preview displays setup instructions until the optional provider is
 * configured. Test-only identity switching never belongs in production applications.
 */
class RealtimeController extends AppController
{
    /**
     * Render subscriptions only when the realtime launcher has configured the hub.
     */
    #[Get('/realtime', name: 'demo:realtime')]
    public function index(): void
    {
        $this->set('ready', Configure::read('Interact.realtime.provider') === 'mercure');
        $this->set('articleId', $this->request->getSession()->read('demo.userId') === 2 ? 99 : 1);
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
