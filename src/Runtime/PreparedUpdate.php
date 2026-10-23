<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Closure;
use LogicException;

/**
 * Holds one authorized update until its action can run inside the selected response transport.
 *
 * The same restored island is retained request-locally. A prepared update executes
 * once, so body inspection cannot accidentally replay application actions.
 */
class PreparedUpdate
{
    protected bool $executed = false;

    /**
     * Retain a server-selected streaming policy and the continuation of the authorized lifecycle.
     *
     * @param Closure(Closure(array<string, mixed>): void): Result $execute Receives an incremental message sink.
     */
    public function __construct(
        public readonly bool $stream,
        public readonly string $id,
        protected readonly Closure $execute,
    ) {
    }

    /**
     * Finish hooks, action execution, and normal result construction exactly once.
     *
     * @param Closure(array<string, mixed>): void|null $send Optional collecting or HTTP sink.
     */
    public function run(?Closure $send = null): Result
    {
        if ($this->executed) {
            throw new LogicException('An island update can execute only once.');
        }

        $this->executed = true;

        return ($this->execute)($send ?? static function (array $message): void {});
    }
}
