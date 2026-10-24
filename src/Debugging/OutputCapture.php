<?php
declare(strict_types=1);

namespace Interact\Debugging;

use Closure;

/**
 * Collects request-local direct output without consuming native template buffers.
 *
 * Only explicitly emitted protocol bytes bypass capture. Retention is bounded;
 * original PHP values never enter diagnostics or signed island state.
 */
class OutputCapture
{
    protected string $output = '';

    protected int $retained = 0;

    protected bool $truncated = false;

    protected bool $reported = false;

    protected ?int $bufferLevel = null;

    /**
     * Retain the server-selected presentation policy for this request only.
     */
    public function __construct(protected readonly bool $viewer)
    {
    }

    /**
     * Capture direct output while preserving the callback's value and exceptions.
     *
     * @template T
     * @param Closure(): T $callback Application execution or response preparation.
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $level = ob_get_level();
        $this->start();
        try {
            return $callback();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_flush();
            }

            $this->bufferLevel = null;
        }
    }

    /**
     * Open a small output buffer so capture does not delay native protocol emission.
     */
    protected function start(): void
    {
        $this->bufferLevel = ob_get_level() + 1;
        ob_start(function (string $output): string {
            $remaining = 262_144 - $this->retained;
            $this->output .= substr($output, 0, $remaining);
            $this->retained += min(strlen($output), $remaining);
            $this->truncated = $this->truncated || strlen($output) > $remaining;

            return '';
        }, 1);
    }

    /**
     * Emit a complete protocol frame without treating its bytes as application output.
     *
     * @param Closure(): void $write Native SSE writer.
     */
    public function pass(Closure $write): void
    {
        $buffered = $this->bufferLevel !== null && ob_get_level() === $this->bufferLevel;
        if ($buffered) {
            ob_end_flush();
        }

        try {
            $write();
        } finally {
            if ($buffered) {
                $this->start();
            }
        }
    }

    /**
     * Drain pending diagnostics once, replacing malformed UTF-8 before JSON encoding.
     *
     * @return array{output: string, truncated: bool, viewer: bool}|null
     */
    public function take(): ?array
    {
        if ($this->output === '' && (!$this->truncated || $this->reported)) {
            return null;
        }

        $data = [
            'output' => mb_scrub($this->output, 'UTF-8'),
            'truncated' => $this->truncated,
            'viewer' => $this->viewer,
        ];
        $this->output = '';
        $this->reported = $this->truncated;

        return $data;
    }
}
