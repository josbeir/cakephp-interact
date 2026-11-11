<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authentication;

use Interact\Authorization\AuthorizationTrait;

/**
 * Combines native authentication access with the independent policy bridge.
 */
class PolicyIsland extends ServiceIsland
{
    use AuthorizationTrait;
}
