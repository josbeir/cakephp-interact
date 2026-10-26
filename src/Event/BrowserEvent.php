<?php
declare(strict_types=1);

namespace Interact\Event;

use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Validates application browser-event names and their JSON detail records.
 *
 * Event data is browser-visible coordination data, never an authorization capability.
 */
readonly class BrowserEvent
{
    /** @var array<string, mixed> Validated, browser-visible payload. */
    public array $detail;

    /**
     * Require a named, finite JSON record before emitting or handling an event.
     *
     * @param array<array-key, mixed> $detail Untrusted event payload.
     * @throws InvalidArgumentException When the event name or JSON record is invalid.
     */
    public function __construct(public string $name, array $detail = [])
    {
        static::assertName($name);
        $this->detail = Values::map($detail);
        Values::validate($detail);
    }

    /**
     * Apply the same literal-name contract to dispatches and listener declarations.
     *
     * @throws InvalidArgumentException When the name is not a lowercase event identifier.
     */
    public static function assertName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9:-]*$/D', $name)) {
            throw new InvalidArgumentException('Browser event names must be lowercase identifiers.');
        }
    }
}
