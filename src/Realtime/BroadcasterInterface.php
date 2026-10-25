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
    /**
     * Deliver one message, throwing on failure without automatic retries.
     */
    public function publish(string $topic, Message $message, bool $private = true): void;
}
