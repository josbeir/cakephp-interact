<?php
declare(strict_types=1);

namespace Interact\Runtime;

use InvalidArgumentException;

/**
 * Applies safe nested edits while preserving scalar types and refusing scalar traversal.
 */
class PathEditor
{
    /**
     * Replace a whole value or apply a validated path within array state.
     *
     * @param list<string> $segments Validated path after its owning property name.
     */
    public function apply(mixed $previous, array $segments, mixed $value): mixed
    {
        if ($segments === []) {
            return $value;
        }

        if (!is_array($previous)) {
            throw new InvalidArgumentException('Nested updates require array state.');
        }

        return $this->replace($previous, $segments, $value);
    }

    /**
     * Copy nested array values while refusing to traverse an existing scalar.
     *
     * @param array<array-key, mixed> $values
     * @param non-empty-list<string> $segments
     *
     * @return array<array-key, mixed>
     */
    protected function replace(array $values, array $segments, mixed $value): array
    {
        $segment = array_shift($segments);
        if ($segments === []) {
            $values[$segment] = $value;
        } else {
            /** @var mixed $child */
            $child = $values[$segment] ?? [];
            if (!is_array($child)) {
                throw new InvalidArgumentException('Cannot traverse scalar state.');
            }

            $values[$segment] = $this->replace($child, $segments, $value);
        }

        return $values;
    }
}
