<?php
declare(strict_types=1);

namespace TestApp\Chat;

use Cake\Http\Exception\ForbiddenException;
use Interact\IslandContext;

/**
 * Derives private conversation ownership from the current demo session and identity.
 *
 * Every island rechecks access before using the same opaque recipient key for
 * database visibility and provider topics. Browser state cannot select another recipient.
 *
 * @consistent-constructor
 */
readonly class ChatIdentity
{
    /**
     * Retain an opaque recipient key rather than exposing the session identifier.
     */
    public function __construct(public string $recipient)
    {
    }

    /**
     * Authorize the current request before deriving any private room capabilities.
     */
    public static function authorize(IslandContext $context): static
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor' || !is_int($identity['id'] ?? null)) {
            throw new ForbiddenException('Chat access is not allowed.');
        }

        return new static(hash('sha256', $context->binding() . '/' . $identity['id']));
    }

    /**
     * Select the private channel shared only by this identity's tabs in the current session.
     */
    public function topic(): string
    {
        return 'demo/private/' . $this->recipient;
    }
}
