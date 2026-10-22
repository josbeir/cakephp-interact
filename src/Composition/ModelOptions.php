<?php
declare(strict_types=1);

namespace Interact\Composition;

use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Validates one child model path and its server synchronization policy.
 * Timing and delay follow the native model directive without evaluating JavaScript.
 */
readonly class ModelOptions
{
    /**
     * Normalize a shorthand path or explicit binding options.
     *
     * @return array{path: string, timing: string, delay: int}
     */
    public function read(mixed $value): array
    {
        $options = is_string($value) ? ['path' => $value] : Values::map($value);
        /** @var mixed $path */
        $path = $options['path'] ?? null;
        /** @var mixed $timing */
        $timing = $options['timing'] ?? 'defer';
        /** @var mixed $delay */
        $delay = $options['delay'] ?? 250;
        if (
            !is_string($path)
            || !in_array($timing, ['defer', 'live', 'change', 'blur'], true)
            || !is_int($delay)
            || $delay < 0
            || $delay > 60_000
            || $timing !== 'live'
            && isset($options['delay'])
            && $delay !== 250
            || array_diff(array_keys($options), ['path', 'timing', 'delay']) !== []
        ) {
            throw new InvalidArgumentException('Invalid child model binding options.');
        }

        Values::path($path);

        return ['path' => $path, 'timing' => $timing, 'delay' => $delay];
    }
}
