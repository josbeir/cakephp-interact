<?php
declare(strict_types=1);

namespace Interact\Streaming;

use Closure;
use Interact\Discovery\Definition;
use Interact\Rendering\ElementRendererInterface;
use Interact\Rendering\RendererInterface;
use Interact\Runtime\Values;
use InvalidArgumentException;
use LogicException;

/**
 * Emits display updates only while its owning streaming action is executing.
 *
 * Content is provisional and never mutates transported state. The final ordinary
 * result remains authoritative; application elements own escaping of HTML values.
 */
class Stream
{
    /**
     * Receive the request-local sink and the same configured renderer used for final output.
     *
     * @param Closure(array<string, mixed>): void $send Validated incremental messages, without transport framing.
     */
    public function __construct(
        protected readonly Closure $send,
        protected readonly RendererInterface $renderer,
        protected readonly Definition $definition,
    ) {}

    /**
     * Append or replace text without interpreting it as HTML.
     */
    public function text(string $to, string $content, bool $replace): void
    {
        $this->target($to, $content, $replace, false);
    }

    /**
     * Render a native element before sending it to an explicit HTML target.
     *
     * @param array<string, mixed> $data Explicit element variables.
     */
    public function element(string $to, string $element, array $data, bool $replace): void
    {
        if (!$this->renderer instanceof ElementRendererInterface) {
            throw new LogicException('This renderer does not support streaming native elements.');
        }

        $this->target($to, $this->renderer->element($this->definition, $element, $data), $replace, true);
    }

    /**
     * Publish progress data immediately, independently of success-only dispatch effects.
     *
     * @param array<string, mixed> $detail JSON-compatible browser-visible values.
     */
    public function event(string $name, array $detail): void
    {
        $this->identifier($name);
        Values::validate(Values::map($detail));
        ($this->send)(['type' => 'event', 'name' => $name, 'detail' => $detail === [] ? (object)[] : $detail]);
    }

    /**
     * Validate display names consistently across text, HTML, and event updates.
     */
    protected function identifier(string $name): void
    {
        if (!preg_match('/^[a-z][a-z0-9:-]*$/D', $name)) {
            throw new InvalidArgumentException('Stream names must be lowercase identifiers.');
        }
    }

    /**
     * Send one normalized target update; transport and browser ownership checks remain separate.
     */
    protected function target(string $to, string $content, bool $replace, bool $html): void
    {
        $this->identifier($to);
        ($this->send)(['type' => 'target', 'to' => $to, 'content' => $content, 'replace' => $replace, 'html' => $html]);
    }
}
