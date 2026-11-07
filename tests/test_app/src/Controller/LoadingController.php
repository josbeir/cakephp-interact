<?php
declare(strict_types=1);

namespace TestApp\Controller;

/**
 * Hosts the deferred loading examples within a normal server-rendered CakePHP page.
 */
class LoadingController extends AppController
{
    /**
     * Render independently loaded cards inside a retained parent island.
     */
    public function index(): void {}

    /**
     * Exercise post-load multipart bindings through the real application middleware.
     */
    public function upload(): void {}
}
