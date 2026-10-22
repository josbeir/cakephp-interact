<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Cake\Core\App;
use Cake\Datasource\Paging\NumericPaginator;
use Cake\Datasource\Paging\SimplePaginator;
use Interact\Discovery\Definition;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Validates explicit paging capabilities and resolves application-owned native paginator configuration.
 * Client values cannot select fields, paginator implementations, or query scopes.
 */
class Configuration
{
    /**
     * Bind immutable compiled declarations, rather than retaining an island instance.
     */
    public function __construct(protected readonly Definition $definition)
    {
    }

    /**
     * Resolve native paginator aliases while requiring numeric bounds for clamping.
     *
     * @param array<string, mixed> $settings Application-owned configuration only.
     */
    public function paginator(array $settings): NumericPaginator
    {
        if (!isset($settings['className'])) {
            return new NumericPaginator();
        }

        /** @var mixed $name */
        $name = $settings['className'];
        $class = is_string($name) ? App::className($name, 'Datasource/Paging', 'Paginator') : null;
        if (
            $class === null
            || !is_a($class, NumericPaginator::class, true)
            || is_a($class, SimplePaginator::class, true)
        ) {
            throw new InvalidArgumentException('Island pagination requires a NumericPaginator or subclass.');
        }

        return new ReflectionClass($class)->newInstance();
    }

    /**
     * Require writable, non-nullable paging state independently of optional URL bindings.
     *
     * @return array<string, \Interact\Discovery\Property> Explicit writable paging capabilities only.
     */
    public function fields(): array
    {
        $fields = [];
        foreach (['page' => 'int', 'sort' => 'string', 'direction' => 'string', 'limit' => 'int'] as $name => $type) {
            $field = $this->definition->properties[$name] ?? null;
            if ($name !== 'page' && $field === null) {
                continue;
            }

            if ($field === null || !$field->writable || $field->nullable || $field->type !== $type) {
                throw new InvalidArgumentException(sprintf(
                    'Pagination requires writable, non-nullable %s state "%s".',
                    $type,
                    $name,
                ));
            }

            $fields[$name] = $field;
        }

        if (isset($fields['sort']) !== isset($fields['direction'])) {
            throw new InvalidArgumentException('Declare pagination sort and direction state together.');
        }

        return $fields;
    }
}
