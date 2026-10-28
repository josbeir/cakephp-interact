<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Composition;

use Interact\Composition\ModelOptions;
use PHPUnit\Framework\TestCase;

/**
 * Verifies child-model synchronization options retain explicit timing choices.
 * An omitted delay must remain distinct from an application-supplied delay.
 */
class ModelOptionsTest extends TestCase
{
    public function testChildDelayOmissionRemainsDistinctFromAnExplicitDelay(): void
    {
        $reader = new ModelOptions();
        $this->assertSame(
            ['path' => 'draft', 'timing' => 'live'],
            $reader->read(['path' => 'draft', 'timing' => 'live']),
        );
        $this->assertSame(
            ['path' => 'draft', 'timing' => 'live', 'delay' => 250],
            $reader->read(['path' => 'draft', 'timing' => 'live', 'delay' => 250]),
        );
    }
}
