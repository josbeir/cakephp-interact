<?php
declare(strict_types=1);

namespace Interact\Loading;

use Cake\Http\ServerRequest;
use InvalidArgumentException;

/**
 * Restricts the initialization envelope to a mount-only operation.
 *
 * Validation happens before signed identity is resolved or application code is constructed.
 */
class Initialization
{
    /**
     * Read the internal marker and reject combinations with edits or effects.
     *
     * @param array<string, mixed> $request Untrusted browser envelope.
     */
    public static function read(array $request): bool
    {
        /** @var mixed $marker */
        $marker = array_key_exists('initialize', $request) ? $request['initialize'] : false;
        if (!is_bool($marker)) {
            throw new InvalidArgumentException('Invalid island initialization marker.');
        }

        if (!$marker) {
            return false;
        }

        $allowed = [
            'action' => null,
            'event' => null,
            'args' => [],
            'updates' => [],
            'urlRestore' => false,
            'upload' => null,
        ];
        foreach ($allowed as $name => $empty) {
            if (isset($request[$name]) && $request[$name] !== $empty) {
                throw new InvalidArgumentException(
                    'Initialization cannot execute edits, actions, transfers, or history restoration.',
                );
            }
        }

        return true;
    }

    /**
     * Reject actual multipart files even when the browser omits its upload operation.
     */
    public static function assertNoFiles(ServerRequest $request): void
    {
        if ($request->getUploadedFiles() !== []) {
            throw new InvalidArgumentException('Initialization cannot receive uploaded files.');
        }
    }

    /**
     * Prevent pending capabilities from being interpreted as active state.
     *
     * @param array<string, mixed> $data Verified snapshot data.
     */
    public static function assertMounted(array $data): void
    {
        if (array_intersect_key($data, ['pending' => true, 'props' => true, 'kind' => true, 'bound' => true]) !== []) {
            throw new InvalidArgumentException('Pending mount tickets are not mounted snapshots.');
        }
    }
}
