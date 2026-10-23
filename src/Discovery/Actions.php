<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Interact\Attribute\Action;
use Interact\Island;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * Compiles explicit callable application methods and their server-owned response policies.
 *
 * Lifecycle methods and base island capabilities cannot become browser actions.
 */
readonly class Actions
{
    /** @var array<string, ReflectionMethod> */
    public array $methods;

    /** @var array<string, bool> */
    public array $renderless;

    /** @var array<string, bool> */
    public array $streaming;

    /**
     * Read exact declaration markers without constructing an application island.
     *
     * @param ReflectionClass<Island> $reflection Concrete island declarations.
     */
    public function __construct(ReflectionClass $reflection)
    {
        $actions = [];
        $renderless = [];
        $streaming = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC
            | ReflectionMethod::IS_PROTECTED
            | ReflectionMethod::IS_PRIVATE) as $method) {
            if ($method->getAttributes(Action::class) === []) {
                continue;
            }

            if (
                ($method->getModifiers() & ReflectionMethod::IS_PUBLIC) === 0
                || $method->isStatic()
                || method_exists(Island::class, $method->getName())
            ) {
                throw new InvalidArgumentException('Only public, nonstatic application methods may be actions.');
            }

            $actions[$method->getName()] = $method;
            $policy = $method->getAttributes(Action::class)[0]->newInstance();
            $renderless[$method->getName()] = !$policy->render;
            $streaming[$method->getName()] = $policy->stream;
        }

        $this->methods = $actions;
        $this->renderless = $renderless;
        $this->streaming = $streaming;
    }
}
