<?php
declare(strict_types=1);

namespace Interact\Command;

use Cake\Command\Command;
use Interact\Upload\UploadStorageInterface;

/**
 * Releases abandoned staged files through the same storage backend as HTTP uploads.
 *
 * Schedule this command for applications that may stop receiving requests; bounded
 * opportunistic cleanup during staging is not a substitute for idle-site cleanup.
 */
class CleanupUploadsCommand extends Command
{
    /**
     * Obtain the application's configured storage implementation through CakePHP's container.
     */
    public function __construct(protected readonly UploadStorageInterface $storage)
    {
        parent::__construct();
    }

    /**
     * Remove expired files and report the count to the application's scheduler.
     */
    public function execute(): ?int
    {
        $this->io->out(sprintf('Removed %d expired uploads.', $this->storage->cleanup()));

        return static::CODE_SUCCESS;
    }
}
