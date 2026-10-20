<?php
declare(strict_types=1);

namespace Interact\Runtime;

/**
 * Carries a completed render to initial-HTML and AJAX response callers.
 *
 * Markup, signed state, validation feedback, and response effects belong to the same
 * render. JSON conversion preserves empty records instead of turning them into lists.
 */
readonly class Result
{
    /**
     * @var array<string, mixed> Declared state and props.
     */
    public array $state;

    /**
     * @var array<string, array{type: string, nullable: bool}> Browser-editable properties.
     */
    public array $writable;

    /**
     * @var array<string, list<string>> Application feedback.
     */
    public array $errors;

    /**
     * @var list<array{name: string, detail: array<string, mixed>}> Successful response effects.
     */
    public array $events;

    /**
     * Collect markup, a signed snapshot, validation feedback, and browser effects.
     *
     * @param array{
     *     state?: array<string, mixed>,
     *     writable?: array<string, array{type: string, nullable: bool}>,
     *     errors?: array<string, list<string>>,
     *     events?: list<array{name: string, detail: array<string, mixed>}>
     * } $data Browser-visible render output.
     */
    public function __construct(public string $id, public string $html, public string $snapshot, array $data = [])
    {
        $this->state = $data['state'] ?? [];
        $this->writable = $data['writable'] ?? [];
        $this->errors = $data['errors'] ?? [];
        $this->events = $data['events'] ?? [];
    }

    /**
     * Build the versioned AJAX envelope while preserving empty JSON records.
     *
     * @return array<string, mixed> Versioned response envelope shared by fetch and the test harness.
     */
    public function payload(): array
    {
        return [
            'version' => 1,
            'id' => $this->id,
            'html' => $this->html,
            'snapshot' => $this->snapshot,
            'state' => (object)$this->state,
            'writable' => (object)$this->writable,
            'errors' => (object)$this->errors,
            'events' => array_map(static fn(array $event): array => [
                'name' => $event['name'],
                'detail' => (object)$event['detail'],
            ], $this->events),
        ];
    }
}
