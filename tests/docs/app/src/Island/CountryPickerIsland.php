<?php
declare(strict_types=1);

namespace App\Island;

use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;

/**
 * Validates a modelable country selection and supports a PHP-driven reset.
 *
 * Save as src/Island/CountryPickerIsland.php.
 */
class CountryPickerIsland extends Island
{
    #[State(writable: true)]
    public string $country = '';

    #[State]
    public bool $confirmed = false;

    /**
     * Check the choice on the server before confirming it.
     */
    #[Action]
    public function confirm(): void
    {
        $this->confirmed = false;
        if (!in_array($this->country, ['BE', 'NL'], true)) {
            $this->setErrors(['country' => ['Choose a country from the list.']]);

            return;
        }

        $this->confirmed = true;
    }

    /**
     * Clear the selected value and its confirmation.
     */
    #[Action]
    public function resetSelection(): void
    {
        $this->country = '';
        $this->confirmed = false;
    }
}
