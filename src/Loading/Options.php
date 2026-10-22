<?php
declare(strict_types=1);

namespace Interact\Loading;

use InvalidArgumentException;

/**
 * Validates application-owned loading choices at the initial mount boundary.
 *
 * Existing keyed children retain their lifecycle regardless of later loading options.
 */
readonly class Options
{
    /**
     * Normalize runtime mount configuration while preserving the existing URL-prefix argument.
     */
    public static function resolve(string|self|null $value): self
    {
        return $value instanceof self ? $value : new self(urlPrefix: $value);
    }

    /**
     * Select eager rendering or an explicit trigger and optional placeholder element.
     *
     * @param array<string, mixed>|string|null $model Explicit child model bindings.
     */
    public function __construct(
        public string $mode = 'eager',
        public ?string $placeholder = null,
        public ?string $urlPrefix = null,
        public string|array|null $model = null,
    ) {
        if (!in_array($mode, ['eager', 'lazy', 'defer', 'manual'], true)) {
            throw new InvalidArgumentException('Use eager, lazy, defer, or manual island loading.');
        }

        if ($placeholder !== null && ($placeholder === '' || $mode === 'eager')) {
            throw new InvalidArgumentException('A placeholder element requires deferred island loading.');
        }
    }
}
