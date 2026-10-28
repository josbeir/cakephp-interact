<?php
declare(strict_types=1);

namespace Interact\Realtime;

use InvalidArgumentException;

/**
 * Offers application-friendly publishing shortcuts independently of the selected provider.
 *
 * Applications retain transaction ownership and can reuse explicit Message instances
 * when their own retry or outbox workflow needs a stable delivery identity.
 */
class Publisher implements PublisherInterface
{
    /**
     * Inject only the provider's delivery capability, keeping construction transport-neutral.
     */
    public function __construct(protected readonly BroadcasterInterface $broadcaster) {}

    /**
     * Deliver an explicitly constructed message without changing its identity.
     */
    public function publish(string $topic, Message $message, bool $private = true): void
    {
        $this->broadcaster->publish($topic, $message, $private);
    }

    /**
     * Publish a named JSON notification or refresh signal.
     *
     * @param array<string, mixed> $detail Browser-visible JSON data.
     */
    public function publishEvent(string $topic, string $event, array $detail = [], bool $private = true): void
    {
        $this->publish($topic, Message::event($event, $detail), $private);
    }

    /**
     * Append or replace literal text in a named stream target.
     *
     * @param array{replace?: bool, private?: bool} $options Optional display replacement and public delivery.
     */
    public function publishText(string $topic, string $event, string $to, string $content, array $options = []): void
    {
        $this->display($topic, Message::text($event, $to, $content, $options['replace'] ?? false), $options);
    }

    /**
     * Publish application-rendered HTML to an explicit HTML display target.
     *
     * @param array{replace?: bool, private?: bool} $options Optional display replacement and public delivery.
     */
    public function publishHtml(string $topic, string $event, string $to, string $content, array $options = []): void
    {
        $this->display($topic, Message::html($event, $to, $content, $options['replace'] ?? false), $options);
    }

    /**
     * Reject misspelled options instead of silently changing delivery expectations.
     *
     * @param array{replace?: bool, private?: bool} $options Display publication options.
     */
    protected function display(string $topic, Message $message, array $options): void
    {
        if (array_diff(array_keys($options), ['replace', 'private']) !== []) {
            throw new InvalidArgumentException('Unknown realtime display publication option.');
        }

        $this->publish($topic, $message, $options['private'] ?? true);
    }
}
