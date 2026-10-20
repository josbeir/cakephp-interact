<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

/**
 * Supplies controlled mkdir, locking, and short-write failures at PHP's stream boundary.
 *
 * Tests retain the real filesystem workflow without changing global error handling
 * or hiding warnings. The wrapper never reads or writes application files.
 */
class UploadFailureStream
{
    public mixed $context;

    public static int $closed = 0;

    public static int $unlocked = 0;

    protected string $path = '';

    /**
     * Open a synthetic stream whose path chooses the failure scenario.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = $path;

        return true;
    }

    /**
     * Simulate a full destination without accepting any source bytes.
     */
    public function stream_write(string $data): int
    {
        return 0;
    }

    /**
     * Accept permission updates without accessing a real file.
     */
    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    /**
     * Report a failed exclusive lock and record every cleanup unlock.
     */
    public function stream_lock(int $operation): bool
    {
        if ($operation === LOCK_UN) {
            static::$unlocked++;

            return true;
        }

        return !str_contains($this->path, 'lock-fail');
    }

    /**
     * Observe resource release after a rejected write or locked operation.
     */
    public function stream_close(): void
    {
        static::$closed++;
    }

    /**
     * Present an existing directory except when testing directory creation failure.
     *
     * @return array{mode: int}|false Synthetic stat data.
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return str_contains($path, 'mkdir-fail') ? false : ['mode' => 0o040_700];
    }

    /**
     * Reject the synthetic private-directory creation request.
     */
    public function mkdir(string $path, int $mode, int $options): bool
    {
        return false;
    }
}
