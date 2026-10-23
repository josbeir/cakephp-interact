<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Island;

use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Exercises application view selection through signed mounts and subsequent updates.
 */
#[Render(template: 'island/configuration')]
class ConfigurationIsland extends Island
{
    #[State]
    public int $count = 0;

    /**
     * Change state so subsequent native rendering can be distinguished from the mount.
     */
    #[Action]
    public function increment(): void
    {
        $this->count++;
    }
}
