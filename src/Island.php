<?php
declare(strict_types=1);

namespace Interact;

use Cake\Datasource\Paging\PaginatedInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;
use Interact\Action\ActionEffects;
use Interact\Action\Outcome;
use Interact\Pagination\Pagination;
use Interact\Validation\ErrorNormalizer;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\UriInterface;

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

    protected ?ActionEffects $actionEffects = null;

    /**
     * @var array<string, mixed> Native CakePHP numeric paginator defaults.
     */
    protected array $paginate = [];

    protected ?Pagination $pagination = null;

    /**
     * Attach the runtime's request-local pagination capability, never transported to the browser.
     *
     * @internal
     */
    public function attachPagination(Pagination $pagination): void
    {
        $this->pagination = $pagination;
    }

    /**
     * Paginate an application-owned query using declared URL state and native CakePHP settings.
     *
     * @param array<string, mixed> $settings Per-call overrides for $paginate.
     * @return \Cake\Datasource\Paging\PaginatedInterface<int, mixed>
     * @throws LogicException When called outside an island request or more than once per request.
     * @throws InvalidArgumentException When paging state or native settings are invalid.
     * @throws \Interact\Security\InvalidSnapshotException When the signed identity requires a remount.
     */
    public function paginate(RepositoryInterface|QueryInterface $object, array $settings = []): PaginatedInterface
    {
        $pagination = $this->pagination ?? throw new LogicException('Pagination requires an island runtime.');

        return $pagination->paginate($this, $object, $settings + $this->paginate);
    }

    /**
     * Provide the runtime's request-local outcome capability, excluded from transported state.
     *
     * @internal
     */
    public function actionEffects(): ActionEffects
    {
        return $this->actionEffects ??= new ActionEffects();
    }

    /**
     * Skip HTML rendering for the current action while preserving accepted state and effects.
     *
     * Validation feedback may still require rendering. This does not terminate the action.
     *
     * @throws \LogicException When called outside a declared action.
     */
    public function skipRender(): void
    {
        $this->actionEffects()->skipRender();
    }

    /**
     * Request full-page navigation after a successful action without terminating PHP execution.
     *
     * @param array<array-key, mixed>|string|UriInterface $url CakePHP route or HTTP(S) URL.
     *
     * @throws \LogicException When called outside an action or after another outcome.
     */
    public function redirect(array|string|UriInterface $url, bool $replace = false, bool $navigate = false): void
    {
        $this->actionEffects()->record(new Outcome('redirect', $url, $replace, $navigate));
    }

    /**
     * Request a native download from an independently authorized, same-origin endpoint.
     *
     * @param array<array-key, mixed>|string|UriInterface $url CakePHP route or download URL.
     *
     * @throws \LogicException When called outside an action or after another outcome.
     */
    public function downloadUrl(array|string|UriInterface $url): void
    {
        $this->actionEffects()->record(new Outcome('download', $url));
    }

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
     * React to accepted writable edits and reactive parent props before an exposed action is invoked.
     *
     * @param list<string> $paths Changed writable paths and reactive prop names, before any action executes.
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
     * Replace response feedback with CakePHP entity errors or application message lists.
     *
     * Nested errors become dotted field paths; empty arrays contribute no messages.
     * The original entity remains unchanged for FormHelper's validation context.
     *
     * @param array<array-key, mixed> $errors Field errors from getErrors() or custom message arrays.
     *
     * @throws InvalidArgumentException When fields or messages violate the error contract.
     */
    public function setErrors(array $errors): void
    {
        $this->errors = ErrorNormalizer::normalize($errors);
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
