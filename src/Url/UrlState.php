<?php
declare(strict_types=1);

namespace Interact\Url;

use Interact\Discovery\Definition;
use Interact\Island;
use Interact\Runtime\PathEditor;
use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Applies declared initial query input and publishes independently owned browser bindings.
 *
 * Prefixes are supplied by the application and retained in signed island identity.
 */
class UrlState
{
    /**
     * Receive the query decoder through the normal service composition boundary.
     */
    public function __construct(protected readonly Codec $codec = new Codec())
    {
    }

    /**
     * Decode page query values before authorization and mount hooks run.
     */
    public function initialize(Island $island, Definition $definition, string $query, ?string $prefix): void
    {
        if ($prefix !== null) {
            Names::require($prefix);
        }

        foreach ($this->bindings($definition) as $path => $binding) {
            $segments = Values::path($path);
            $field = $definition->properties[array_shift($segments)];
            $name = ($prefix === null ? '' : $prefix . '_') . $binding->name;
            /** @var mixed $value */
            $value = $this->codec->read($query, $name, $binding);
            $field->assign(
                $island,
                $segments === [] ? $value : new PathEditor()->apply($field->read($island), $segments, $value),
            );
        }
    }

    /**
     * Export validated query names and immutable defaults for browser synchronization.
     *
     * @return array<string, array{name: string, type: string, default: mixed, history: bool, nullable?: bool}>
     */
    public function metadata(Definition $definition, ?string $prefix): array
    {
        if ($prefix !== null) {
            Names::require($prefix);
        }

        $metadata = [];
        $used = [];
        foreach ($this->bindings($definition) as $name => $binding) {
            if (isset($used[$binding->name])) {
                throw new InvalidArgumentException('URL query keys must be unique within an island.');
            }

            $used[$binding->name] = true;
            $metadata[$name] = [
                'name' => ($prefix === null ? '' : $prefix . '_') . $binding->name,
                'type' => $binding->type,
                ...($binding->nullable ? ['nullable' => true] : []),
                'default' => $binding->default,
                'history' => $binding->history,
            ];
        }

        return $metadata;
    }

    /**
     * Collect scalar and structured declarations under their explicit writable paths.
     *
     * @return array<string, Binding>
     */
    protected function bindings(Definition $definition): array
    {
        $bindings = [];
        foreach ($definition->properties as $field) {
            $bindings += $field->bindings();
        }

        return $bindings;
    }

    /**
     * Require a complete URL-property set for an action-free history restoration.
     *
     * @param array<string, mixed> $updates Typed browser edits.
     *
     * @return list<string> Explicitly restored properties, including unchanged values.
     */
    public function restorationPaths(Definition $definition, array $updates): array
    {
        $fields = $this->metadata($definition, null);
        if ($fields === [] || array_diff_key($fields, $updates) !== [] || array_diff_key($updates, $fields) !== []) {
            throw new InvalidArgumentException('History restoration requires exactly the declared URL properties.');
        }

        return array_keys($fields);
    }
}
