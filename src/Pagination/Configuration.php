<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Cake\Core\App;
use Cake\Datasource\Paging\NumericPaginator;
use Cake\Datasource\Paging\SimplePaginator;
use Interact\Discovery\Definition;
use Interact\Island;
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
     * Select the sole declaration or require an explicitly owned state for multiple listings.
     */
    public function select(Island $island, ?PaginationState $paging): PaginationProperty
    {
        $properties = $this->definition->paginations();
        if (!$paging instanceof PaginationState && count($properties) === 1) {
            return array_values($properties)[0];
        }

        foreach ($properties as $property) {
            if ($property->state($island) === $paging) {
                return $property;
            }
        }

        throw new InvalidArgumentException(
            'Select a declared PaginationState; multiple listings require the paging argument.',
        );
    }
}
