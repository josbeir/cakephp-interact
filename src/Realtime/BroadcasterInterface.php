<?php
declare(strict_types=1);

namespace Interact\Realtime;

/**
 * Defines the provider delivery boundary beneath the application-facing publisher.
 *
 * Adapters implement delivery once; message-construction shortcuts remain shared
 * across Mercure, WebSocket providers, and controlled testing doubles.
 */
interface BroadcasterInterface
{
    // Privacy is forwarded delivery data; Mago cannot inspect implementations from this interface.
    // @mago-expect lint:no-boolean-flag-parameter
    /**
     * Deliver one message, throwing on failure without automatic retries.
     */
    public function publish(string $topic, Message $message, bool $private = true): void;
}
