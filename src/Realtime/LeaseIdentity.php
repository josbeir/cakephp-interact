<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Interact\Action\RouteContext;
use Interact\Runtime\Values;
use InvalidArgumentException;

/**
 * Validates the minimal signed restoration data accepted by subscription authorization.
 *
 * This identity contains no normal snapshot or child/upload capabilities. Every
 * renewal reconstructs an island and checks current access before issuing a grant.
 */
readonly class LeaseIdentity
{
    public string $alias;

    public string $id;

    public string $endpoint;

    /**
     * @var array<string, mixed> Previously accepted props and state.
     */
    public array $state;

    public RouteContext $routing;

    /**
     * Reject incomplete signed data before island construction.
     *
     * @param array<string, mixed> $data Verified lease data.
     */
    public function __construct(array $data)
    {
        $this->alias = $this->string($data, 'alias');
        $this->id = $this->string($data, 'id');
        $this->endpoint = $this->string($data, 'endpoint');
        $this->state = Values::map($data['state'] ?? null);
        $this->routing = new RouteContext($data['routing'] ?? []);
    }

    /**
     * Read a required non-empty identity field after signature verification.
     *
     * @param array<string, mixed> $data Signed identity data.
     */
    protected function string(array $data, string $name): string
    {
        /** @var mixed $value */
        $value = $data[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('Invalid subscription lease identity.');
        }

        return $value;
    }
}
