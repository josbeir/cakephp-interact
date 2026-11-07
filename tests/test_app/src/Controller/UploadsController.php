<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Http\Response;
use Cake\Routing\Router;
use Interact\Http\UpdateHandler;

/**
 * Hosts the upload example and demonstrates an application-owned island endpoint.
 *
 * The shared handler processes both updates and transfers. Route defaults declare
 * the scope early enough for middleware exceptions to retain island JSON feedback.
 */
class UploadsController extends AppController
{
    /**
     * Select this controller's named endpoint once for all mounted examples.
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->viewBuilder()->addHelper('Interact', [
            'className' => 'Interact.Interact',
            'endpoint' => Router::url(['_name' => 'demo:uploads:update']),
            'scope' => 'uploads-demo',
        ]);
    }

    /**
     * Render the public document-upload demonstration through a normal action template.
     */
    public function index(): void {}

    /**
     * Delegate JSON and multipart handling through CakePHP's action dependency injection.
     */
    public function update(UpdateHandler $interact): Response
    {
        return $interact->handle($this->getRequest(), $this->getResponse());
    }
}
