<?php
declare(strict_types=1);

namespace Interact\Streaming;

use Cake\Http\Session;

/**
 * Freezes snapshot identity only when a streaming response commits and releases its session.
 *
 * Ordinary updates retain native session rotation behavior. After release, nested renders
 * and signing reuse the captured identity without starting another session transaction.
 */
class SessionBinding
{
    protected ?string $releasedId = null;

    /**
     * Retain the request's native session rather than a second session implementation.
     */
    public function __construct(protected readonly Session $session)
    {
    }

    /**
     * Follow session renewals until emission freezes the browser binding.
     */
    public function read(): string
    {
        if ($this->releasedId !== null) {
            return $this->releasedId;
        }

        $this->session->start();

        return $this->session->id();
    }

    /**
     * Capture the latest identity and commit application session changes before action execution.
     */
    public function release(): void
    {
        $this->releasedId = $this->session->id();
        $this->session->close();
    }
}
