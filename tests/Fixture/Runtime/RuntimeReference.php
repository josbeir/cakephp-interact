<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Runtime;

use Interact\Runtime\Runtime;

/**
 * Connects a nested-render callback to its runtime after both collaborators have been constructed.
 */
class RuntimeReference
{
    public Runtime $runtime;
}
