<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Action\RouteContext;
use Interact\Loading\Initialization;
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

    public RouteContext $routing;

    /**
     * @var array<string, mixed> Declared state and mount props.
     */
    public array $state;

    /**
     * @var array<string, array{id: string, alias: string, props: array<string, mixed>, urlPrefix?: string}> Verified direct children.
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
     */
    public function __construct(array $data)
    {
        Initialization::assertMounted($data);

        if (!is_string($data['alias'] ?? null) || !is_string($data['id'] ?? null) || !is_string($data['key'] ?? null)) {
            throw new InvalidArgumentException('Invalid signed island identity.');
        }

        $this->renderedErrors = RenderPolicy::renderedErrors($data);
        $data += ['state' => null, 'children' => null, 'uploads' => [], 'routing' => []];
        $this->routing = new RouteContext($data['routing']);
        $this->alias = $data['alias'];
        $this->id = $data['id'];
        $this->key = $data['key'];
        $this->urlPrefix = Names::optional($data['urlPrefix'] ?? null);
        $this->state = Values::map($data['state']);
        $children = [];

        /** @var mixed $child */
        foreach (Values::map($data['children']) as $key => $child) {
            if (!is_array($child) || !is_string($child['id'] ?? null) || !is_string($child['alias'] ?? null)) {
                throw new InvalidArgumentException('Invalid signed child identity.');
            }

            $children[$key] = [
                'id' => $child['id'],
                'alias' => $child['alias'],
                'props' => Values::map($child['props'] ?? null),
                ...(isset($child['urlPrefix']) ? ['urlPrefix' => Names::require($child['urlPrefix'])] : []),
            ];
        }

        $this->children = $children;
        $this->uploads = UploadReferences::read($data['uploads']);
    }
}
