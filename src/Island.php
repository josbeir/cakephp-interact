<?php
declare(strict_types=1);

namespace Interact;

use InvalidArgumentException;

/**
 * Owns the application behavior and response feedback for one interactive region.
 *
 * The runtime constructs a fresh instance on every request. Override authorize(),
 * mount(), updated(), and viewData() at their documented lifecycle points; expose
 * browser actions explicitly. Injected services never become snapshot state.
 */
abstract class Island
{
    /**
     * @var array<string, list<string>> Application-owned validation feedback.
     */
    protected array $errors = [];

    /**
     * @var list<array{name: string, detail: array<string, mixed>}> Effects for this response only.
     */
    protected array $events = [];

    /**
     * Authorize initial mounts and updates before application hooks and rendering.
     */
    public function authorize(IslandContext $context): void
    {
    }

    /**
     * Initialize state once, after props are assigned and access is authorized.
     */
    public function mount(): void
    {
    }

    /**
     * React to accepted browser edits before an exposed action is invoked.
     *
     * @param list<string> $paths Accepted browser paths, before any action executes.
     */
    public function updated(array $paths): void
    {
    }

    /**
     * Supply computed template variables without adding them to the signed snapshot.
     *
     * @return array<string, mixed> Additional view data, never automatically serialized.
     */
    public function viewData(): array
    {
        return [];
    }

    /**
     * Attach application validation feedback to the next render.
     *
     * @param array<string, list<string>> $errors Existing CakePHP/application validation errors.
     */
    public function setErrors(array $errors): void
    {
        $this->errors = $errors;
    }

    /**
     * Read validation feedback for rendering and response status selection.
     *
     * @return array<string, list<string>> Current renderable validation errors.
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Queue a native browser event for a successful update response.
     *
     * @param array<string, mixed> $detail Browser-visible, untrusted event data.
     */
    public function dispatch(string $name, array $detail = []): void
    {
        if (!preg_match('/^[a-z][a-z0-9:-]*$/D', $name)) {
            throw new InvalidArgumentException('Browser event names must be lowercase identifiers.');
        }

        $this->events[] = ['name' => $name, 'detail' => $detail];
    }

    /**
     * Read the response effects queued by this fresh island instance.
     *
     * @return list<array{name: string, detail: array<string, mixed>}> Events applied after successful rendering.
     */
    public function events(): array
    {
        return $this->events;
    }
}
