<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Security;

use Interact\Runtime\SnapshotData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Rejects malformed identities even when their snapshot integrity has been verified.
 * These boundaries run through the same declarations and runtime contracts as application islands.
 */
class SnapshotIdentityTest extends TestCase
{
    public function testSignedSchemaStillRequiresValidIdentities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData(['id' => 0]);
    }

    public function testSignedChildrenRequireValidIdentities(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SnapshotData([
            'alias' => 'a',
            'id' => 'id',
            'key' => 'key',
            'state' => [],
            'children' => ['key' => ['id' => false]],
        ]);
    }
}
