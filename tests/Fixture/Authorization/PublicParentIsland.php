<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authorization;

use Interact\Attribute\Render;
use Interact\IslandContext;

/**
 * Explicitly acknowledges a public request without granting access to protected child islands.
 */
#[Render(template: 'unused')]
class PublicParentIsland extends BridgeIsland
{
    /**
     * Skip only the middleware's request-level required-check bookkeeping.
     */
    public function authorize(IslandContext $context): void
    {
        $this->Authorization->skipAuthorization();
    }
}
