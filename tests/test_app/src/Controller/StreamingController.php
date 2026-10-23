<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Http\Response;
use Cake\Routing\Attribute\Get;

/**
 * Hosts action streaming through normal CakePHP routes and the shared application layout.
 */
class StreamingController extends AppController
{
    /**
     * Render a self-contained text and element streaming example.
     */
    #[Get('/streaming', name: 'demo:streaming')]
    public function index(): void
    {
    }

    /**
     * Host a gated browser fixture for observing output before action completion.
     */
    #[Get('/demo/streaming-probe', name: 'demo:streaming-probe')]
    public function probe(): void
    {
    }

    /**
     * Observe the current session through an independent native request.
     */
    #[Get('/demo/streaming-session', name: 'demo:streaming-session')]
    public function session(): Response
    {
        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode(['id' => $this->request->getSession()->id()], JSON_THROW_ON_ERROR));
    }
}
