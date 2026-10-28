<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Routing\Attribute\Get;

/**
 * Hosts the renderless action example through an ordinary CakePHP page route.
 */
class RenderlessController extends AppController
{
    /**
     * Render the FormHelper example with application-owned layout and styling.
     */
    #[Get('/renderless', name: 'demo:renderless')]
    public function index(): void {}
}
