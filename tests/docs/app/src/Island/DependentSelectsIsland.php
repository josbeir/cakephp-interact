<?php
declare(strict_types=1);

namespace App\Island;

use App\Model\Table\CitiesTable;
use Cake\ORM\Locator\LocatorAwareTrait;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\UpdatedPaths;

/**
 * Provides country-dependent city choices and validates the selected membership.
 */
class DependentSelectsIsland extends Island
{
    use LocatorAwareTrait;

    protected CitiesTable $cities;

    #[State(writable: true)]
    public string $country = 'BE';

    #[State(writable: true)]
    public ?int $cityId = null;

    #[State]
    public bool $confirmed = false;

    /**
     * Assign the request-local Table before authorization and state restoration.
     */
    public function initialize(): void
    {
        $table = $this->fetchTable('Cities');
        assert($table instanceof CitiesTable, 'The configured collaborator must satisfy its declared contract.');
        $this->cities = $table;
    }

    /**
     * Clear the selected city when its owning country changes.
     *
     * @param UpdatedPaths $paths Reset a stale city when its country changes.
     */
    public function updated(UpdatedPaths $paths): void
    {
        $this->confirmed = false;
        if ($paths->has('country')) {
            $this->cityId = null;
        }
    }

    /**
     * Recheck submitted membership rather than trusting the browser's rendered options.
     */
    #[Action]
    public function confirm(): void
    {
        if (
            !in_array($this->country, ['BE', 'NL'], true)
            || $this->cityId === null
            || !$this->cities->find('selection', country: $this->country, cityId: $this->cityId)->count()
        ) {
            $this->setErrors(['cityId' => ['Choose a city in the selected country.']]);

            return;
        }

        $this->confirmed = true;
    }

    /**
     * Load allowed city choices for the current country through the model finder.
     *
     * @return array<string, mixed> Model-provided choices for the current country.
     */
    public function viewData(): array
    {
        return ['cities' => $this->cities->find('inCountry', country: $this->country)->all()];
    }
}
