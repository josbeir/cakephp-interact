<?php
declare(strict_types=1);

namespace Interact\Test\TestCase\Uploads;

use Cake\Console\CommandRunner;
use Cake\Console\ConsoleIoInterface;
use Cake\TestSuite\TestCase;
use Interact\Test\TestSuite\Uploads\UploadFixture;
use TestApp\Application;

/**
 * Boots the actual plugin command through CakePHP's discovery and dependency container.
 */
class UploadCommandTest extends TestCase
{
    public function testScheduledCleanupUsesTheRegisteredStorageBackend(): void
    {
        $fixture = new UploadFixture();
        $fixture->configure();
        try {
            $io = $this->createMock(ConsoleIoInterface::class);
            $io->expects($this->once())->method('out')->with($this->stringContains('expired uploads.'));
            $this->assertSame(0, new CommandRunner(new Application(CONFIG), 'cake')->run(
                ['cake', 'interact.cleanup_uploads'],
                $io,
            ));
        } finally {
            $fixture->close();
        }
    }
}
