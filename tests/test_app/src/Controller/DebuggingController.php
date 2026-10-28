<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Routing\Attribute\Get;

/**
 * Hosts the interactive debugging example and a separate hostile-output test probe.
 */
class DebuggingController extends AppController
{
    /**
     * Mount two clients so browser tests can inspect independent diagnostic requests.
     */
    #[Get('/demo/debugging', name: 'demo:debugging')]
    public function probe(): void
    {
        $this->set('templateDump', $this->getRequest()->getQuery('template') === '1');
        $this->set('loading', $this->getRequest()->getQuery('loading') === 'manual' ? 'manual' : 'eager');
    }

    /**
     * Let application authors try native dumps through normal island interactions.
     */
    #[Get('/debugging', name: 'demo:debugging-example')]
    public function index(): void {}
}
