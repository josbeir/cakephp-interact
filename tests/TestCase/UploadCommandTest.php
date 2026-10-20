<?php
declare(strict_types=1);

namespace Interact\Test\TestCase;

use Cake\Console\CommandRunner;
use Cake\Console\ConsoleIoInterface;
use Cake\TestSuite\TestCase;
use TestApp\Application;

/**
 * Boots the actual plugin command through CakePHP's discovery and dependency container.
 */
class UploadCommandTest extends TestCase
{
    public function testScheduledCleanupUsesTheRegisteredStorageBackend(): void
    {
        $io = $this->createMock(ConsoleIoInterface::class);
        $io->expects($this->once())->method('out')->with(static::stringContains('expired uploads.'));
        static::assertSame(0, new CommandRunner(new Application(CONFIG), 'cake')->run(
            ['cake', 'interact.cleanup_uploads'],
            $io,
        ));
    }
}
