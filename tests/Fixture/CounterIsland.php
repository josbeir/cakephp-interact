<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Exercises declared props, writable/locked state, native arguments, validation, and browser
 * effects.
 */
#[Render(template: 'Islands/counter', tag: 'section')]
class CounterIsland extends Island
{
    #[Prop]
    public int $recordId;

    #[State(writable: true)]
    public int $count = 0;

    #[State(writable: true)]
    public array $form = ['title' => '', 'tags' => []];

    #[State(writable: true)]
    public ?float $amount = null;

    #[State(writable: true)]
    public bool $enabled = false;

    #[State]
    public string $locked = 'server';

    /**
     * Initial mounting is observable through the locked value.
     */
    public function mount(): void
    {
        $this->locked = 'mounted';
    }

    /**
     * Record accepted edit paths so tests can verify lifecycle ordering.
     *
     * @param list<string> $paths Confirm updates precede action execution.
     */
    public function updated(array $paths): void
    {
        $this->locked = implode(',', $paths);
    }

    /**
     * Explicitly callable action with a default native argument.
     */
    #[Action]
    public function increment(int $by = 1): void
    {
        $this->count += $by;
        $this->dispatch('counter-saved', ['count' => $this->count]);
    }

    /**
     * Exercise rendering application-owned validation feedback.
     */
    #[Action]
    public function invalid(): void
    {
        $this->setErrors(['count' => ['Invalid count.']]);
        $this->dispatch('not-sent');
    }

    /**
     * Accept native JSON-compatible arguments without PHP's reflection scalar coercion.
     */
    #[Action]
    public function accept(string $title, bool $enabled, array $tags, ?float $amount): void
    {
        $this->form = ['title' => $title, 'tags' => $tags];
        $this->enabled = $enabled;
        $this->amount = $amount;
    }

    /**
     * Provide computed markup data without adding another transported property.
     *
     * @return array<string, mixed> Computed output without transport side effects.
     */
    public function viewData(): array
    {
        return ['heading' => 'Counter'];
    }
}
