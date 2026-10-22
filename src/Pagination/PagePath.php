<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Cake\Http\ServerRequest;
use Interact\Security\InvalidSnapshotException;
use InvalidArgumentException;

/**
 * Validates the server-captured page path carried by active and pending signed identities.
 * Query input never supplies a path or a navigation origin.
 */
class PagePath
{
    /**
     * Accept legacy identities without a path while rejecting non-local or malformed paths.
     */
    public static function read(mixed $path): ?string
    {
        if ($path === null) {
            return null;
        }

        if (!is_string($path) || !preg_match('~^/(?!/)[^?#\\\\\\x00-\\x20]*$~D', $path)) {
            throw new InvalidArgumentException('Invalid signed mounting page path.');
        }

        return $path;
    }

    /**
     * Require a remount for legacy identities before application hooks or actions can run.
     *
     * @throws InvalidSnapshotException When the signed identity predates mounting page paths.
     * @throws InvalidArgumentException When the signed path is malformed or non-local.
     */
    public static function required(mixed $path): string
    {
        return (
            static::read($path) ?? throw new InvalidSnapshotException('Reload the page to restore its mounting path.')
        );
    }

    /**
     * Keep the inherited mount path when children first appear during an update.
     */
    public static function resolve(?string $path, ServerRequest $request): string
    {
        return $path ?? $request->getUri()->getPath();
    }
}
