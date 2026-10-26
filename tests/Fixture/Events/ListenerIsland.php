<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Events;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;

/**
 * Exercises typed browser-event actions through the real authorized runtime.
 * Payloads remain ordinary untrusted action inputs rather than signed state.
 */
#[Render(element: 'events')]
class ListenerIsland extends Island
{
    /** @var array<string, mixed> Last arguments received by an action. */
    #[State]
    public array $received = [];

    #[State(writable: true)]
    public string $draft = '';

    /**
     * Allow tests to revoke access after the initial mount.
     */
    public function authorize(IslandContext $context): void
    {
        if ($context->request->getAttribute('deny') === true) {
            throw new ForbiddenException('Access revoked.');
        }
    }

    /**
     * Record named arguments and their native optional defaults.
     */
    #[Action(on: 'article-saved')]
    public function saved(int $id, string $title = 'Default', ?string $note = null, bool $published = false): void
    {
        $this->received = ['id' => $id, 'title' => $title, 'note' => $note, 'published' => $published];
        $this->dispatch('handled', id: $id);
    }

    /**
     * Permit another independent method to listen for the same event.
     */
    #[Action(on: 'article-saved')]
    public function refresh(): void
    {
        $this->received = ['refreshed' => true];
    }

    /**
     * Exercise compound JSON arguments without rendering.
     *
     * @param array<string, mixed> $selection Browser-provided structured selection.
     */
    #[Action(render: false, on: 'selection')]
    public function select(float $score, array $selection): void
    {
        $this->received = ['score' => $score, 'selection' => $selection];
    }

    /**
     * Preserve normal validation rendering and success-event suppression.
     */
    #[Action(render: false, on: 'invalid')]
    public function invalid(): void
    {
        $this->setErrors(['draft' => ['Invalid draft.']]);
        $this->dispatch('suppressed');
    }

    /**
     * A callable method does not implicitly become an event listener.
     */
    #[Action]
    public function ordinary(): void
    {
        $this->received = ['ordinary' => true];
    }

    /**
     * Event binding retains the declared streaming capability and rendering preference.
     */
    #[Action(render: false, stream: true, on: 'report')]
    public function report(int $id): void
    {
        $this->received = ['id' => $id];
        $this->streamEvent('progress', ['id' => $id]);
    }
}
