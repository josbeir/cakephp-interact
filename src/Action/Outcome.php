<?php
declare(strict_types=1);

namespace Interact\Action;

use Psr\Http\Message\UriInterface;

/**
 * Records one request-local browser outcome selected by application action code.
 * Destinations are resolved and checked before they enter an update response.
 */
readonly class Outcome
{
    /**
     * Preserve native CakePHP destination inputs until routing context is available.
     *
     * @param array<array-key, mixed>|string|UriInterface $destination Route or URL supplied by the application.
     */
    public function __construct(
        public string $kind,
        public array|string|UriInterface $destination,
        public bool $replace = false,
        public bool $navigate = false,
    ) {
    }
}
