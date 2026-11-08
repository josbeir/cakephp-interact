<?php
declare(strict_types=1);

namespace Interact\Test\TestSuite\Bake;

use Cake\Console\Arguments;
use Interact\Command\Bake\IslandCommand;

/**
 * Redirects application scaffold output to disposable storage while retaining native plugin paths.
 */
class ScaffoldCommand extends IslandCommand
{
    public function __construct(protected string $sourcePath)
    {
        parent::__construct();
    }

    /**
     * Keep generation tests outside the shared demo application's source tree.
     */
    public function getPath(Arguments $args): string
    {
        return $this->plugin === null ? $this->sourcePath . 'Island/' : parent::getPath($args);
    }
}
