<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\AsIsland;
use Interact\IslandContext;

/**
 * Rechecks current request permissions on mount and resume, independently from signed resource
 * identity.
 */
#[AsIsland('protected-counter', template: 'Islands/counter')]
class ProtectedIsland extends CounterIsland
{
    /**
     * Deny changed permission state before hooks and actions can run.
     */
    public function authorize(IslandContext $context): void
    {
        if ($context->request->getAttribute('allowed') !== true) {
            throw new ForbiddenException('Not allowed.');
        }
    }
}
