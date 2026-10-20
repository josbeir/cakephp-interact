<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Demonstrates an application-owned select widget using ordinary writable state and validation.
 *
 * Confirmation is transient: the example does not persist a record or imply authorization.
 */
#[AsIsland('enhanced-select', element: 'Interact/enhanced_select')]
class EnhancedSelect extends Island
{
    /**
     * Fixed demo choices; applications can provide their own finder-derived options.
     *
     * @var array<string, string>
     */
    protected const array COUNTRIES = [
        'AT' => 'Austria',
        'BE' => 'Belgium',
        'DK' => 'Denmark',
        'FI' => 'Finland',
        'FR' => 'France',
        'DE' => 'Germany',
        'IE' => 'Ireland',
        'IT' => 'Italy',
        'NL' => 'Netherlands',
        'NO' => 'Norway',
        'PT' => 'Portugal',
        'ES' => 'Spain',
        'SE' => 'Sweden',
        'CH' => 'Switzerland',
    ];

    #[State(writable: true)]
    public string $country = '';

    #[State]
    public bool $confirmed = false;

    #[State]
    public int $refreshes = 0;

    #[State]
    public bool $visible = true;

    /**
     * Clear prior confirmation when a new selection reaches PHP.
     *
     * @param list<string> $paths Changed writable state paths.
     */
    public function updated(array $paths): void
    {
        $this->confirmed = false;
    }

    /**
     * Validate membership on the server instead of trusting the widget's choices.
     */
    #[Action]
    public function confirm(): void
    {
        if (!isset(static::COUNTRIES[$this->country])) {
            $this->setErrors(['country' => ['Choose a country from the list.']]);

            return;
        }

        $this->confirmed = true;
    }

    /**
     * Reset PHP state and let the custom model update the visible widget silently.
     */
    #[Action]
    public function resetSelection(): void
    {
        $this->country = '';
        $this->confirmed = false;
    }

    /**
     * Render unrelated PHP feedback without replacing the widget instance.
     */
    #[Action]
    public function refreshExample(): void
    {
        $this->refreshes++;
    }

    /**
     * Remove or restore the wrapper so Alpine owns destruction and initialization.
     */
    #[Action]
    public function toggleWidget(): void
    {
        $this->visible = !$this->visible;
    }

    /**
     * Supply display choices without transporting them in the signed snapshot.
     *
     * @return array<string, mixed> The application's accepted country choices.
     */
    public function viewData(): array
    {
        return ['countries' => static::COUNTRIES];
    }
}
