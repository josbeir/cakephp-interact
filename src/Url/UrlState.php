<?php
declare(strict_types=1);

namespace Interact\Url;

use Interact\Discovery\Definition;
use Interact\Island;
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
        foreach ($this->metadata($definition, $prefix) as $property => $metadata) {
            $field = $definition->properties[$property];
            assert($field->url instanceof Binding, 'URL metadata refers to compiled URL declarations.');
            $field->assign($island, $this->codec->read($query, $metadata['name'], $field->url));
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
        foreach ($definition->properties as $name => $field) {
            if ($field->url === null) {
                continue;
            }

            if (isset($used[$field->url->name])) {
                throw new InvalidArgumentException('URL query keys must be unique within an island.');
            }

            $used[$field->url->name] = true;
            $metadata[$name] = [
                'name' => ($prefix === null ? '' : $prefix . '_') . $field->url->name,
                'type' => $field->url->type,
                ...($field->url->nullable ? ['nullable' => true] : []),
                'default' => $field->url->default,
                'history' => $field->url->history,
            ];
        }

        return $metadata;
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
