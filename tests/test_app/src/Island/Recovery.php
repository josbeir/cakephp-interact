<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\ServiceUnavailableException;
use Cake\Http\Exception\UnauthorizedException;
use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotFailureReason;
use InvalidArgumentException;
use RuntimeException;

/**
 * Demonstrates application-owned feedback while retaining an editable draft after failures.
 *
 * Simulated exceptions are confined to the fixture app; real applications use their
 * normal middleware, authorization, and business operations to produce these failures.
 */
#[AsIsland('recovery', element: 'Interact/recovery')]
class Recovery extends Island
{
    #[State(writable: true)]
    public string $message = '';

    #[State]
    public int $saves = 0;

    /**
     * Accept a draft to demonstrate an explicit successful attempt after a transient failure.
     */
    #[Action]
    public function save(): void
    {
        $this->saves++;
    }

    /**
     * Exercise recovery categories without weakening the update route's real protections.
     */
    #[Action]
    public function simulate(string $failure): void
    {
        throw match ($failure) {
            'expired' => new InvalidSnapshotException('Fixture expiry.', reason: SnapshotFailureReason::Expired),
            'session' => new InvalidSnapshotException(
                'Fixture session.',
                reason: SnapshotFailureReason::SessionChanged,
            ),
            'unauthenticated' => new UnauthorizedException('Fixture authentication.'),
            'forbidden' => new ForbiddenException('Fixture permission.'),
            'unavailable' => new ServiceUnavailableException('Private fixture service diagnostics.'),
            'server' => new RuntimeException('Private fixture diagnostics must never reach the browser.'),
            default => new InvalidArgumentException('Choose an available failure.'),
        };
    }
}
