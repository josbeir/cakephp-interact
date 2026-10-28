<?php
declare(strict_types=1);

namespace Interact\Realtime;

/**
 * Publishes explicit application messages independently of any browser or island request.
 *
 * Applications own transaction boundaries and durable delivery. Implementations throw
 * on publication failures rather than automatically retrying uncertain operations.
 */
interface PublisherInterface extends BroadcasterInterface
{
    // Privacy is forwarded delivery data; Mago cannot inspect implementations from this interface.
    // @mago-expect lint:no-boolean-flag-parameter
    /**
     * Publish a named JSON notification or refresh signal.
     *
     * @param array<string, mixed> $detail Browser-visible JSON data.
     */
    public function publishEvent(string $topic, string $event, array $detail = [], bool $private = true): void;

    /**
     * Publish literal text using the existing display-target vocabulary.
     *
     * @param array{replace?: bool, private?: bool} $options Display replacement and delivery privacy.
     */
    public function publishText(string $topic, string $event, string $to, string $content, array $options = []): void;

    /**
     * Publish application-rendered HTML to an explicit HTML display target.
     *
     * @param array{replace?: bool, private?: bool} $options Display replacement and delivery privacy.
     */
    public function publishHtml(string $topic, string $event, string $to, string $content, array $options = []): void;
}
