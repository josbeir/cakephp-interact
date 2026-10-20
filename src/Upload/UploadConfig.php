<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\Utility\Fs\Path;
use Cake\Utility\Text;
use InvalidArgumentException;

/**
 * Holds temporary-storage limits independently of application file-validation rules.
 *
 * Configuration applies to every transfer, including abandoned files whose responses
 * never reached the browser. Applications can replace this container registration.
 */
readonly class UploadConfig
{
    public string $directory;

    public int $maxFileSize;

    public int $maxFiles;

    public int $maxIslandSize;

    public int $maxSessionSize;

    public int $maxSessionFiles;

    public int $ttl;

    /**
     * Validate application configuration before any filesystem work begins.
     *
     * @param array<array-key, mixed> $options Overrides from Interact.uploads configuration.
     */
    public function __construct(array $options = [])
    {
        /** @var mixed $directory */
        $directory = $options['directory'] ?? TMP . 'interact/uploads';
        if (!is_string($directory) || $directory === '') {
            throw new InvalidArgumentException('Uploads require a private storage directory.');
        }

        $this->directory = Path::join($directory);
        $this->maxFileSize = $this->size($options, 'maxFileSize', 10 * 1024 * 1024);
        $this->maxFiles = $this->positive($options, 'maxFiles', 10);
        $this->maxIslandSize = $this->size($options, 'maxIslandSize', 50 * 1024 * 1024);
        $this->maxSessionSize = $this->size($options, 'maxSessionSize', 100 * 1024 * 1024);
        $this->maxSessionFiles = $this->positive($options, 'maxSessionFiles', 100);
        $this->ttl = $this->positive($options, 'ttl', 86_400);
    }

    /**
     * Normalize integer bytes and CakePHP's human-readable file sizes before validation.
     *
     * @param array<array-key, mixed> $options Application settings.
     */
    protected function size(array $options, string $name, int $default): int
    {
        /** @var mixed $value */
        $value = $options[$name] ?? $default;
        if (is_string($value)) {
            $value = Text::parseFileSize(trim($value), 0);
        }

        return $this->positive([$name => $value], $name, $default);
    }

    /**
     * Reject limits that would disable bounded staging or overflow counters.
     *
     * @param array<array-key, mixed> $options Application settings.
     */
    protected function positive(array $options, string $name, int $default): int
    {
        /** @var mixed $value */
        $value = $options[$name] ?? $default;
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('Upload setting "%s" must be a positive integer.', $name));
        }

        return $value;
    }
}
