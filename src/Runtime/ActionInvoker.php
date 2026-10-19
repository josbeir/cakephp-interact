<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Island;
use InvalidArgumentException;
use ReflectionNamedType;

/**
 * Validates explicitly exposed action signatures before invoking application behavior.
 */
class ActionInvoker
{
    /**
     * Reject unknown actions, unsupported arguments, and invalid argument counts before application
     * execution.
     *
     * @param list<mixed> $arguments Reject invalid signatures before constructing or authorizing an island.
     */
    public function validate(Definition $definition, ?string $action, array $arguments): void
    {
        if ($action === null) {
            if ($arguments !== []) {
                throw new InvalidArgumentException('Synchronization cannot have action arguments.');
            }

            return;
        }

        $method = $definition->actions[$action] ?? throw new InvalidArgumentException('Unknown island action.');
        $parameters = $method->getParameters();
        if (count($arguments) < $method->getNumberOfRequiredParameters() || count($arguments) > count($parameters)) {
            throw new InvalidArgumentException('Invalid island action argument count.');
        }

        /** @var mixed $value */
        foreach ($arguments as $index => $value) {
            Values::validate($value);
            $type = $parameters[$index]->getType();
            $matches =
                $type instanceof ReflectionNamedType
                && match ($type->getName()) {
                    'string' => is_string($value),
                    'int' => is_int($value),
                    'float' => is_int($value) || is_float($value),
                    'bool' => is_bool($value),
                    'array' => is_array($value),
                    default => false,
                };
            if (!$matches && !($value === null && $type instanceof ReflectionNamedType && $type->allowsNull())) {
                throw new InvalidArgumentException('Invalid island action argument type.');
            }
        }
    }

    /**
     * Invoke only after state validation and application authorization have succeeded.
     */
    public function invoke(Definition $definition, Island $island, UpdateRequest $request): void
    {
        if ($request->action !== null) {
            $callback = $definition->actions[$request->action]->getClosure($island);
            $callback(...$request->arguments);
        }
    }
}
