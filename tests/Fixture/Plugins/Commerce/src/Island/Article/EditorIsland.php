<?php
declare(strict_types=1);

namespace Commerce\Island\Article;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Supplies a nested plugin island whose alias intentionally differs from its template path.
 * Updates exercise the same native view lookup as the initial mount.
 */
class EditorIsland extends Island
{
    #[State]
    public int $count = 0;

    /**
     * Change transported state so update rendering can be verified.
     */
    #[Action]
    public function increment(): void
    {
        $this->count++;
    }
}
