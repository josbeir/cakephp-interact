<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\State;

use Interact\Attribute\Action;
use Interact\Attribute\Render;
use Interact\Island;

/**
 * Supplies mount-time feedback to both eager and deferred rendering paths.
 * The next action starts with fresh feedback so its response exercises normal cleanup.
 */
#[Render(template: 'unused')]
class InitialErrorsIsland extends Island
{
    public const array ERRORS = ['value' => ['Choose "<value>" & retry.']];

    /**
     * Publish initial application feedback after normal mount authorization.
     */
    public function mount(): void
    {
        $this->setErrors(self::ERRORS);
    }

    /**
     * Accept a successful request whose fresh instance has no validation errors.
     */
    #[Action]
    public function clear(): void {}
}
