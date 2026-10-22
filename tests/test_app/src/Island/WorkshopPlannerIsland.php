<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\UpdatedPaths;

/**
 * Demonstrates conditional fields, checkbox groups, and a quote calculated on the server.
 *
 * Confirmation validates the current draft but creates no booking or payment. All choices
 * are transient island state, so reloading the page starts a new example.
 */
class WorkshopPlannerIsland extends Island
{
    #[State(writable: true)]
    public string $format = 'remote';

    #[State(writable: true)]
    public int $attendees = 2;

    /**
     * Optional extras selected through a native checkbox group.
     *
     * @var array<array-key, mixed>
     */
    #[State(writable: true)]
    public array $extras = [];

    #[State(writable: true)]
    public bool $business = false;

    #[State(writable: true)]
    public string $company = '';

    #[State]
    public bool $confirmed = false;

    /**
     * Clear confirmation whenever another draft reaches the server.
     *
     * @param UpdatedPaths $paths Accepted edits to the planner.
     */
    public function updated(UpdatedPaths $paths): void
    {
        $this->confirmed = false;
    }

    /**
     * Validate all choices, including fields required only for a business quote.
     */
    #[Action]
    public function confirm(): void
    {
        $errors = [];
        if (!in_array($this->format, ['remote', 'studio'], true)) {
            $errors['format'] = ['Choose an online or studio workshop.'];
        }

        if ($this->attendees < 1 || $this->attendees > 12) {
            $errors['attendees'] = ['Choose between 1 and 12 attendees.'];
        }

        if (
            array_filter(
                $this->extras,
                static fn(mixed $extra): bool => !in_array($extra, ['recording', 'materials'], true),
            ) !== []
        ) {
            $errors['extras'] = ['Choose an available workshop extra.'];
        }

        if ($this->business && (trim($this->company) === '' || mb_strlen($this->company) > 100)) {
            $errors['company'] = ['Enter a company name of 1 to 100 characters.'];
        }

        $this->setErrors($errors);
        $this->confirmed = $errors === [];
    }

    /**
     * Derive display prices from allowed options rather than accepting a browser-supplied total.
     *
     * @return array<string, mixed> Computed quote data, excluded from the signed state.
     */
    public function viewData(): array
    {
        $rate = $this->format === 'studio' ? 75 : 45;
        $perPerson = in_array('materials', $this->extras, true) ? 15 : 0;
        $recording = in_array('recording', $this->extras, true) ? 30 : 0;
        $total = (($rate + $perPerson) * max(1, min(12, $this->attendees))) + $recording;

        return ['rate' => $rate, 'total' => $total];
    }
}
