<?php
declare(strict_types=1);

namespace Interact\Http;

use Cake\Http\ServerRequest;
use Interact\Runtime\Values;
use Interact\Upload\UploadLimits;
use InvalidArgumentException;

/**
 * Validates bounded JSON and multipart envelopes before the runtime opens signed state.
 */
class UpdatePayload
{
    /**
     * Preserve native upload limits and require the shared versioned request vocabulary.
     *
     * @return array<string, mixed> Validated request record; individual fields remain untrusted.
     */
    public function read(ServerRequest $request): array
    {
        /** @var mixed $body */
        $body = $request->getData();
        if (str_starts_with($request->getHeaderLine('Content-Type'), 'multipart/form-data')) {
            UploadLimits::assertRequest($request);
            /** @var mixed $payload */
            $payload = $request->getData('payload');
            if (!is_string($payload) || strlen($payload) > 1_048_576) {
                throw new InvalidArgumentException('Invalid upload envelope.');
            }

            /** @var mixed $body */
            $body = json_decode($payload, true);
        } elseif (strlen((string)$request->getBody()) > 1_048_576) {
            throw new InvalidArgumentException('Invalid island request size.');
        }

        if (!is_array($body) || ($body['version'] ?? null) !== 1) {
            throw new InvalidArgumentException('Invalid island request.');
        }

        return Values::map($body);
    }
}
