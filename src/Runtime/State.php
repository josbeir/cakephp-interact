<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\Url\UrlState;
use InvalidArgumentException;

/**
 * Exports declared snapshot values and applies edits to writable properties.
 *
 * Transport validation preserves native scalar distinctions and reports changed paths
 * for application hooks. Application validation and persistence remain outside this service.
 */
class State
{
    /**
     * Receive the URL transport used alongside signed snapshot state.
     */
    public function __construct(protected readonly UrlState $urlState = new UrlState())
    {
    }

    /**
     * Apply declared query values before initial authorization and mounting.
     */
    public function initializeUrl(Island $island, Definition $definition, string $query, ?string $prefix): void
    {
        $this->urlState->initialize($island, $definition, $query, $prefix);
    }

    /**
     * Publish the query bindings owned by one island instance.
     *
     * @return array<string, array{name: string, type: string, default: mixed, history: bool}>
     */
    public function urlMetadata(Definition $definition, ?string $prefix): array
    {
        return $this->urlState->metadata($definition, $prefix);
    }

    /**
     * Validate and identify the complete state set restored by browser history.
     *
     * @param array<string, mixed> $updates Typed browser edits.
     *
     * @return list<string> Explicitly restored properties, including unchanged values.
     */
    public function restorationPaths(Definition $definition, array $updates): array
    {
        return $this->urlState->restorationPaths($definition, $updates);
    }

    /**
     * Read declared props and state, rejecting uninitialized or unsupported values.
     *
     * @return array<string, mixed> Transported props and state, preserving native scalar distinctions.
     */
    public function export(Island $island, Definition $definition): array
    {
        $values = [];
        foreach ($definition->properties as $name => $property) {
            if (!$property->reflection->isInitialized($island)) {
                throw new InvalidArgumentException(sprintf('Uninitialized island state "%s".', $name));
            }

            /** @var mixed $value */
            $value = $property->reflection->getValue($island);
            Values::validate($value);
            $property->url?->validate($value);
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * Apply edits only to writable properties and return the paths that changed.
     *
     * @param array<string, mixed> $updates Browser edits.
     *
     * @return list<string> Changed paths.
     */
    public function apply(Island $island, Definition $definition, array $updates): array
    {
        $values = $this->export($island, $definition);

        $changes = [];
        /** @var mixed $value */
        foreach ($updates as $path => $value) {
            $segments = Values::path($path);
            $name = array_shift($segments);
            $property = $definition->properties[$name] ?? null;
            if ($property === null || !$property->writable) {
                throw new InvalidArgumentException('This island property is not writable.');
            }

            Values::validate($value);
            $property->url?->validate($value);

            /** @var mixed $previous */
            $previous = $values[$name];
            /** @var mixed $replacement */
            $replacement = new PathEditor()->apply($previous, $segments, $value);
            if ($previous !== $replacement) {
                $property->assign($island, $replacement);
                $values[$name] = $replacement;
                $changes[] = $path;
            }
        }

        return $changes;
    }
}
