<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Exception\ServiceUnavailableException;
use Cake\Http\Exception\UnauthorizedException;
use Interact\Attribute\Action;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;
use Interact\Security\InvalidSnapshotException;
use Interact\Security\SnapshotFailureReason;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * Demonstrates application-owned feedback while retaining an editable draft after failures.
 *
 * Simulated exceptions are confined to the fixture app; real applications use their
 * normal middleware, authorization, and business operations to produce these failures.
 */
class RecoveryIsland extends Island
{
    #[State(writable: true)]
    public string $message = '';

    #[State]
    public int $saves = 0;

    /**
     * Require the current demo sign-in independently of the browser's signed state.
     */
    public function authorize(IslandContext $context): void
    {
        if ($context->request->getSession()->read('demo.recoveryAuthenticated') === false) {
            throw new UnauthorizedException('The demo sign-in expired.');
        }
    }

    /**
     * Accept a draft to demonstrate an explicit successful attempt after a transient failure.
     */
    #[Action]
    public function save(): void
    {
        $this->saves++;
    }

    /**
     * Restore only this application's message draft for review, without accepting or saving it.
     */
    #[Action]
    public function restoreDraft(string $draft): void
    {
        try {
            /** @var mixed $values */
            $values = json_decode($draft, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $values = null;
        }

        if (!is_array($values) || array_keys($values) !== ['message'] || !is_string($values['message'])) {
            $this->setErrors(['_form' => ['Paste a draft exported from this recovery example.']]);

            return;
        }

        $this->message = $values['message'];
        $this->dispatch('draft-restored');
    }

    /**
     * Expire the demo's sign-in so the next authorized request also rejects the old page.
     */
    #[Action]
    public function expireSignIn(): void
    {
        $this->getRequest()->getSession()->write('demo.recoveryAuthenticated', false);

        throw new UnauthorizedException('The demo sign-in expired.');
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
