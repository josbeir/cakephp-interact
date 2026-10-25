<?php
declare(strict_types=1);

namespace Interact\Realtime;

use Interact\Runtime\Values;
use InvalidArgumentException;
use LengthException;

/**
 * Carries a named notification or provisional display update to a provider.
 *
 * Message identities survive application-managed retries. Recipients supply their own
 * island identities; published data never carries signed state or action results.
 *
 * @consistent-constructor
 */
readonly class Message
{
    public string $id;

    /**
     * Validate JSON detail and optional display content before assigning a delivery identity.
     *
     * @param array<string, mixed> $detail Browser-visible JSON data.
     * @param array{type: string, to: string, content: string, replace: bool, html: bool}|null $display Display-only target data.
     */
    public function __construct(
        public string $name,
        public array $detail = [],
        public ?array $display = null,
        ?string $id = null,
    ) {
        static::validateName($name);
        Values::validate(Values::map($detail));
        if ($display !== null) {
            static::validateName($display['to']);
        }

        $this->id = $id ?? bin2hex(random_bytes(16));
        if (!preg_match('/^[a-zA-Z0-9:_-]{1,128}$/D', $this->id)) {
            throw new InvalidArgumentException('Invalid realtime message identity.');
        }
    }

    /**
     * Publish JSON event data without changing island state.
     *
     * @param array<string, mixed> $detail Browser-visible JSON record.
     */
    public static function event(string $name, array $detail = []): static
    {
        return new static($name, $detail);
    }

    /**
     * Append or replace literal text in explicitly subscribed stream targets.
     */
    public static function text(string $name, string $to, string $content, bool $replace = false): static
    {
        return new static($name, display: [
            'type' => 'target',
            'to' => $to,
            'content' => $content,
            'replace' => $replace,
            'html' => false,
        ]);
    }

    /**
     * Deliver application-rendered HTML to explicit display-only HTML targets.
     */
    public static function html(string $name, string $to, string $content, bool $replace = false): static
    {
        return new static($name, display: [
            'type' => 'target',
            'to' => $to,
            'content' => $content,
            'replace' => $replace,
            'html' => true,
        ]);
    }

    /**
     * Encode the provider-neutral envelope, including the topic for shared-connection routing.
     */
    public function encode(string $topic): string
    {
        Subscription::validateTopic($topic);
        $json = json_encode([
            'version' => 1,
            'id' => $this->id,
            'topic' => $topic,
            'event' => $this->name,
            'detail' => (object)$this->detail,
            ...($this->display === null ? [] : ['display' => $this->display]),
        ], JSON_THROW_ON_ERROR | JSON_HEX_TAG);
        if (strlen($json) > 1_048_576) {
            throw new LengthException('Realtime messages must not exceed 1 MiB.');
        }

        return $json;
    }

    /**
     * Use the existing browser-event and stream-target identifier vocabulary.
     */
    public static function validateName(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9:-]*$/D', $name)) {
            throw new InvalidArgumentException('Realtime event and target names must be lowercase identifiers.');
        }
    }
}
