<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Exports short-lived connection credentials to the corresponding browser adapter.
 *
 * Provider-specific data is validated by that adapter and remains outside reactive
 * island state. This grant cannot authorize normal island requests.
 */
readonly class Grant
{
    /**
     * Retain an adapter name, JSON connection data, and the credential expiry.
     *
     * @param array<string, mixed> $data Provider-specific browser configuration.
     */
    public function __construct(public string $driver, public array $data, public int $expires)
    {
        Message::validateName($driver);
        Values::validate(Values::map($data));
        if ($expires < 1) {
            throw new InvalidArgumentException('Realtime grants require an expiry.');
        }
    }

    /**
     * Export the common grant boundary without interpreting provider credentials.
     *
     * @return array{driver: string, data: array<string, mixed>, expires: int}
     */
    public function data(): array
    {
        return ['driver' => $this->driver, 'data' => $this->data, 'expires' => $this->expires];
    }
}
