<?php
declare(strict_types=1);

namespace TestApp\Command;

use Cake\Command\ServerCommand as CakeServerCommand;

/**
 * Enables the bundled Mercure hub when CakePHP starts the demo with FrankenPHP.
 *
 * Ordinary PHP server behavior remains CakePHP's. The demo launcher supplies
 * ephemeral signing keys; SSE compression stays disabled for immediate delivery.
 */
class ServerCommand extends CakeServerCommand
{
    /**
     * Extend CakePHP's existing host, port, document-root, and binary handling.
     */
    protected function frankenphpCommand(): string
    {
        return parent::frankenphpCommand() . ' --mercure --no-compress';
    }
}
