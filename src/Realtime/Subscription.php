<?php
declare(strict_types=1);

namespace Interact\Realtime;

use InvalidArgumentException;

/**
 * Declares how one authorized island receives an exact topic and optional event.
 *
 * Private subscriptions are the default. This declaration selects delivery behavior;
 * the provider must independently enforce the corresponding topic authorization.
 *
 * @consistent-constructor
 */
readonly class Subscription
{
    /**
     * Validate the topic, event filter, and delivery mode before exporting metadata.
     */
    public function __construct(
        public string $topic,
        public string $mode = 'refresh',
        public ?string $event = null,
        public bool $private = true,
    ) {
        static::validateTopic($topic);
        if (!in_array($mode, ['refresh', 'event', 'stream'], true)) {
            throw new InvalidArgumentException('Invalid realtime subscription mode.');
        }

        if ($event !== null) {
            Message::validateName($event);
        }
    }

    /**
     * Invalidate the island when a matching change arrives.
     */
    public static function refresh(string $topic, ?string $event = null, bool $private = true): static
    {
        return new static($topic, 'refresh', $event, $private);
    }

    /**
     * Deliver matching notifications through the interact:push browser event.
     */
    public static function listen(string $topic, ?string $event = null, bool $private = true): static
    {
        return new static($topic, 'event', $event, $private);
    }

    /**
     * Deliver matching provisional display messages through existing stream targets.
     */
    public static function stream(string $topic, ?string $event = null, bool $private = true): static
    {
        return new static($topic, 'stream', $event, $private);
    }

    /**
     * Export only the browser's routing and delivery vocabulary.
     *
     * @return array{topic: string, mode: string, event: string|null, private: bool}
     */
    public function data(): array
    {
        return ['topic' => $this->topic, 'mode' => $this->mode, 'event' => $this->event, 'private' => $this->private];
    }

    /**
     * Require bounded exact topics, excluding the provider's all-topics selector.
     */
    public static function validateTopic(string $topic): void
    {
        if ($topic === '' || $topic === '*' || strlen($topic) > 2048 || preg_match('/[\x00-\x20\x7f]/', $topic)) {
            throw new InvalidArgumentException('Realtime topics must be bounded, non-empty exact identifiers.');
        }
    }
}
