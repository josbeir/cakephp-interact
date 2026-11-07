<?php
declare(strict_types=1);

namespace TestApp\Controller;

/**
 * Hosts the interactive debugging example and a separate hostile-output test probe.
 */
class DebuggingController extends AppController
{
    /**
     * Mount two clients so browser tests can inspect independent diagnostic requests.
     */
    public function probe(): void
    {
        $this->set('templateDump', $this->getRequest()->getQuery('template') === '1');
        $this->set('loading', $this->getRequest()->getQuery('loading') === 'manual' ? 'manual' : 'eager');
    }

    /**
     * Let application authors try native dumps through normal island interactions.
     */
    public function index(): void {}
}
