<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Authorization;

use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\Render;
use Interact\Attribute\State;
use Interact\Attribute\Upload;
use Interact\IslandContext;
use Interact\Realtime\Subscription;
use Interact\UpdatedPaths;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Exercises policy checks through the real lifecycle while recording which application work ran.
 *
 * Permission comes from the current middleware identity, never from writable browser state.
 */
#[Render(template: 'island/Authorization/policy')]
class PolicyIsland extends BridgeIsland
{
    /** @var list<string> Application lifecycle observations. */
    public static array $trace = [];

    #[Prop]
    public bool $subscribe = false;

    #[State(writable: true)]
    public string $draft = '';

    #[Upload]
    public ?UploadedFileInterface $file = null;

    /**
     * Check current access before hooks, uploads, actions, or view work.
     */
    public function authorize(IslandContext $context): void
    {
        static::$trace[] = 'authorize';
        $this->Authorization->authorize(new Resource(), 'access');
    }

    /**
     * Observe mount work only after the current policy succeeds.
     */
    public function mount(): void
    {
        static::$trace[] = 'mount';
    }

    /**
     * Observe accepted drafts without granting permission to invoke an action.
     */
    public function updated(UpdatedPaths $paths): void
    {
        static::$trace[] = 'updated';
    }

    /**
     * Increment an ordinary rendered action after authorization.
     */
    #[Action]
    public function increment(): void
    {
        static::$trace[] = 'action';
        $this->count++;
    }

    /**
     * Preserve the same policy boundary when skipping view work.
     */
    #[Action(render: false)]
    public function retain(): void
    {
        $this->increment();
    }

    /**
     * Check an additional resource selected by an untrusted action argument.
     */
    #[Action]
    public function other(bool $allowed): void
    {
        $this->Authorization->authorize(new Resource($allowed), 'access');
        $this->increment();
    }

    /**
     * Check an additional resource during deferred stream execution.
     */
    #[Action(stream: true)]
    public function generate(): void
    {
        $this->Authorization->authorize(
            new Resource(
                $this->getRequest()->getAttribute('streamAllowed', true) === true,
            ),
            'access',
        );
        $this->stream('draft', 'Authorized output');
        $this->draft = 'Complete';
    }

    /**
     * Observe view work independently from renderless actions and lease renewal.
     *
     * @return array<string, mixed> No additional template data is needed.
     */
    public function viewData(): array
    {
        static::$trace[] = 'view';

        return [];
    }

    /**
     * Declare a protected topic only when the test explicitly enables renewal.
     *
     * @return list<Subscription>
     */
    public function subscriptions(IslandContext $context): array
    {
        return $this->subscribe ? [Subscription::refresh('resources/1')] : [];
    }
}
