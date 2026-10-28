<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Routing\Attribute\Get;

/**
 * Hosts the deferred loading examples within a normal server-rendered CakePHP page.
 */
class LoadingController extends AppController
{
    /**
     * Render independently loaded cards inside a retained parent island.
     */
    #[Get('/loading', name: 'demo:loading')]
    public function index(): void {}

    /**
     * Exercise post-load multipart bindings through the real application middleware.
     */
    #[Get('/demo/loading-upload', name: 'demo:loading-upload')]
    public function upload(): void {}
}
