<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Rendering;

use Closure;
use Interact\Discovery\Definition;
use Interact\Rendering\RendererInterface;

/**
 * Substitutes application markup at the renderer extension boundary without mocking island
 * behavior.
 */
class CallbackRenderer implements RendererInterface
{
    /**
     * Accept application-markup behavior for a test without substituting lifecycle services.
     *
     * @param \Closure(\Interact\Discovery\Definition, array<string, mixed>): string $render Rendering callback.
     */
    public function __construct(protected readonly Closure $render)
    {
    }

    /**
     * Delegate rendering to the configured callback with the current island variables.
     *
     * @param array<string, mixed> $data Explicit island variables.
     */
    public function render(Definition $definition, array $data): string
    {
        return ($this->render)($definition, $data);
    }
}
