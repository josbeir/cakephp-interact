<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Action\RouteContext;
use Interact\Composition\Relation;
use Interact\Loading\Initialization;
use Interact\Pagination\PagePath;
use Interact\Upload\UploadReferences;
use Interact\Url\Names;
use InvalidArgumentException;

/**
 * Validates the signed protocol schema before restored data reaches island construction and
 * rendering.
 */
readonly class SnapshotData
{
    public string $alias;

    public string $id;

    public string $key;

    public bool $renderedErrors;

    public ?string $urlPrefix;

    /**
     * Server-selected subscription endpoint retained independently of HTTP update context.
     */
    public ?string $subscriptionEndpoint;

    public RouteContext $routing;

    public ?string $pagePath;

    public ?Relation $relation;

    /**
     * @var array<string, mixed> Declared state and mount props.
     */
    public array $state;

    /**
     * @var array<string, array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string, relation?: array{parentId: string, models: array<string, array{path: string, timing: string, delay?: int}>}, values?: array<string, mixed>}> Verified direct children.
     */
    public array $children;

    /**
     * @var array<string, list<string>> Signed references; upload objects remain request-local.
     */
    public array $uploads;

    /**
     * Validate signed identity, state, and direct-child records before restoring an island.
     *
     * @param array<string, mixed> $data Cryptographically verified, but not yet schema-checked data.
     * @param self|null $parent Server-owned parent identity when creating a new child mount.
     */
    public function __construct(array $data, ?self $parent = null)
    {
        Initialization::assertMounted($data);

        if (!is_string($data['alias'] ?? null) || !is_string($data['id'] ?? null) || !is_string($data['key'] ?? null)) {
            throw new InvalidArgumentException('Invalid signed island identity.');
        }

        /** @var mixed $subscriptionEndpoint */
        $subscriptionEndpoint = $data['subscriptionEndpoint'] ?? $parent?->subscriptionEndpoint;
        if ($subscriptionEndpoint !== null && (!is_string($subscriptionEndpoint) || $subscriptionEndpoint === '')) {
            throw new InvalidArgumentException('Invalid signed subscription endpoint.');
        }

        $this->subscriptionEndpoint = $subscriptionEndpoint;
        $this->renderedErrors = RenderPolicy::renderedErrors($data);
        $data += ['state' => null, 'children' => null, 'uploads' => [], 'routing' => [], 'pagePath' => null];
        $this->routing = new RouteContext($data['routing']);
        $this->pagePath = PagePath::read($data['pagePath']);
        $this->relation = Relation::read($data['relation'] ?? null);
        $this->alias = $data['alias'];
        $this->id = $data['id'];
        $this->key = $data['key'];
        $this->urlPrefix = Names::optional($data['urlPrefix'] ?? null);
        $this->state = Values::map($data['state']);
        $this->children = new SnapshotChildren()->read($data['children']);
        $this->uploads = UploadReferences::read($data['uploads']);
    }
}
