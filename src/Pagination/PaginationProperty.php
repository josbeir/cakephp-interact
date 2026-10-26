<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Interact\Attribute\Pagination;
use Interact\Discovery\Property;
use Interact\Island;
use Interact\Url\Binding;
use InvalidArgumentException;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Compiles one explicitly declared PaginationState into a closed scalar transport schema.
 *
 * The PHP object remains request-local. Browser edits cannot add fields, change settings,
 * choose object classes, or make a fixed page size writable.
 */
readonly class PaginationProperty extends Property
{
    protected Selection $selection;

    /**
     * Validate the native property and its independently owned paging declaration.
     */
    public function __construct(ReflectionProperty $reflection, public Pagination $declaration)
    {
        parent::__construct($reflection, false, true);
        $this->selection = new Selection($declaration);
        $this->bindings();
    }

    /**
     * Restrict object transport to the one explicitly supported built-in paging type.
     */
    protected function transportType(ReflectionNamedType $type): string
    {
        if ($type->getName() !== PaginationState::class || $type->allowsNull()) {
            throw new InvalidArgumentException('Pagination requires a non-nullable PaginationState property.');
        }

        return 'array';
    }

    /**
     * Return enabled scalar fields and their immutable reload defaults.
     *
     * @return array<string, int|string>
     */
    public function fields(): array
    {
        return $this->selection->fields();
    }

    /**
     * Initialize a fresh, owned object before application setup without restoring browser input.
     */
    public function initialize(Island $island): void
    {
        $this->reflection->setValue($island, new PaginationState(...$this->selection->defaults));
    }

    /**
     * Read the native object without allowing it into snapshot encoding.
     */
    public function state(Island $island): PaginationState
    {
        /** @var mixed $state */
        $state = $this->reflection->getValue($island);
        assert($state instanceof PaginationState, 'Compiled paging properties contain PaginationState.');

        return $state;
    }

    /**
     * Encode only enabled scalar fields from the request-local object.
     *
     * @return array<string, int|string>
     */
    public function read(Island $island): array
    {
        $state = $this->state($island);
        $values = array_intersect_key([
            'page' => $state->page,
            'sort' => $state->sort,
            'direction' => $state->direction,
            'limit' => $state->limit,
        ], $this->fields());
        $this->validate($values);

        return $values;
    }

    /**
     * Restore a validated scalar selection while retaining the owned object identity.
     */
    public function assign(Island $island, mixed $value): void
    {
        $values = $this->selection->decode($value);
        $state = $this->state($island);
        $state->page = $values['page'];
        $state->sort = $values['sort'];
        $state->direction = $values['direction'];
        $state->limit = $values['limit'];
    }

    /**
     * Reject missing, extra, mistyped, and browser-unsafe fields before application behavior.
     */
    public function validate(mixed $value): void
    {
        $this->selection->decode($value);
    }

    /**
     * Describe exact scalar capabilities, never exposing the object as an arbitrary writable array.
     *
     * @return array<string, array{type: string, nullable: bool}>
     */
    public function writableFields(): array
    {
        $fields = [];
        foreach ($this->fields() as $name => $default) {
            $fields[$this->name . '.' . $name] = ['type' => is_int($default) ? 'int' : 'string', 'nullable' => false];
        }

        return $fields;
    }

    /**
     * Bind selected scalar paths to flat query aliases independently of PHP object ownership.
     *
     * @return array<string, Binding>
     */
    public function bindings(): array
    {
        return new PaginationBindings($this->declaration)->compile($this->name, $this->fields());
    }
}
