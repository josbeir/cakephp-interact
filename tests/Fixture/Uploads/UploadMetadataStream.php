<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Uploads;

/**
 * Models metadata publication with observable writes and controlled filesystem failures.
 *
 * Tests can inspect the published record during staging and simulate short writes
 * or a rejected rename without suppressing native warnings or touching real uploads.
 */
class UploadMetadataStream
{
    public mixed $context;

    /**
     * @var array<string, string> Virtual file contents.
     */
    public static array $files = [];

    /**
     * @var list<string|null> Published contents observed while writing staging bytes.
     */
    public static array $observed = [];

    public static bool $shortWrite = false;

    public static bool $rejectRename = false;

    public static int $closed = 0;

    protected string $path = '';

    /**
     * Create an unpublished staging record.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->path = $path;
        static::$files[$path] = '';

        return true;
    }

    /**
     * Observe the published path and optionally stop after accepting a few bytes.
     */
    public function stream_write(string $data): int
    {
        static::$observed[] = static::$files['interactmetadata://record.json'] ?? null;
        $length = static::$shortWrite ? max(0, 3 - strlen(static::$files[$this->path])) : strlen($data);
        static::$files[$this->path] .= substr($data, 0, $length);

        return $length;
    }

    /**
     * Accept permission changes for synthetic private records.
     */
    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    /**
     * Complete buffered writes before publication.
     */
    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * Track resource release on success and failure.
     */
    public function stream_close(): void
    {
        static::$closed++;
    }

    /**
     * Expose only existing synthetic regular files to cleanup.
     *
     * @return array{mode: int}|false Synthetic file status.
     */
    public function url_stat(string $path, int $flags): array|false
    {
        return array_key_exists($path, static::$files) ? ['mode' => 0o100_600] : false;
    }

    /**
     * Publish the complete staging record in a single mutation.
     */
    public function rename(string $from, string $to): bool
    {
        if (static::$rejectRename) {
            return false;
        }

        static::$files[$to] = static::$files[$from];
        unset(static::$files[$from]);

        return true;
    }

    /**
     * Remove an unpublished staging record after a failed operation.
     */
    public function unlink(string $path): bool
    {
        unset(static::$files[$path]);

        return true;
    }
}
