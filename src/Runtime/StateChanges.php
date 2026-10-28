<?php
declare(strict_types=1);

namespace Interact\Runtime;

/**
 * Identifies addressable value changes independently of the submitted update shape.
 *
 * Container replacements and list size changes report their owning path. Unaddressable
 * array keys also fall back to their container rather than inventing ambiguous dotted paths.
 *
 * @internal
 */
class StateChanges
{
    /**
     * Report changed nested values while preserving missing values and scalar types.
     *
     * @return list<string> Exact paths for the updated lifecycle hook.
     */
    public function paths(mixed $previous, mixed $current, string $path): array
    {
        if ($previous === $current) {
            return [];
        }

        if (
            !is_array($previous)
            || !is_array($current)
            || array_is_list($previous) !== array_is_list($current)
            || array_is_list($previous)
            && count($previous) !== count($current)
        ) {
            return [$path];
        }

        $paths = [];
        foreach (array_unique([...array_keys($previous), ...array_keys($current)]) as $key) {
            $before = array_key_exists($key, $previous);
            $after = array_key_exists($key, $current);
            if ($before && $after && $previous[$key] === $current[$key]) {
                continue;
            }

            if (!preg_match('/^[a-zA-Z0-9_-]+$/D', (string)$key)) {
                return [$path];
            }

            $child = $path . '.' . $key;
            array_push(
                $paths,
                ...$before && $after
                    ? $this->paths($previous[$key], $current[$key], $child)
                    : [$child],
            );
        }

        return $paths;
    }
}
