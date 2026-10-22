<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Cake\Datasource\Paging\Exception\PageOutOfBoundsException;
use Cake\Datasource\Paging\NumericPaginator;
use Cake\Datasource\Paging\PaginatedInterface;
use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;
use Interact\Discovery\Definition;
use Interact\Island;
use Interact\IslandContext;
use Interact\Runtime\SnapshotData;
use Interact\Runtime\State;
use Interact\Url\UrlState;
use InvalidArgumentException;
use LogicException;

/**
 * Applies declared paging state to an application-owned query after authorization.
 *
 * Only one dataset is evaluated per request. Native results and link bookkeeping stay
 * request-local; accepted scalar parameters are normalized before snapshot export.
 */
class Pagination
{
    protected bool $used = false;

    /**
     * Compose paging against the same compiled declaration and signed mount identity as rendering.
     */
    public function __construct(
        protected readonly Definition $definition,
        protected readonly SnapshotData $identity,
        protected readonly IslandContext $context,
        protected readonly ?string $query = null,
        protected readonly ?Configuration $configuration = null,
    ) {
    }

    /**
     * Fetch one native result page, bounding stale page numbers without replaying application actions.
     *
     * @param array<string, mixed> $settings Native numeric paginator configuration.
     * @return \Cake\Datasource\Paging\PaginatedInterface<int, mixed>
     * @throws \Interact\Security\InvalidSnapshotException When the signed identity requires a remount.
     * @throws InvalidArgumentException When paging declarations or settings are invalid.
     * @throws LogicException When the island attempts to paginate more than one dataset.
     */
    public function paginate(
        Island $island,
        RepositoryInterface|QueryInterface $object,
        array $settings,
    ): PaginatedInterface {
        $path = PagePath::required($this->identity->pagePath);
        if ($this->used) {
            throw new LogicException('An island can paginate one dataset per request.');
        }

        $this->used = true;
        $configuration = $this->configuration ?? new Configuration($this->definition);
        $fields = $configuration->fields();
        $params = [];
        foreach ($fields as $name => $field) {
            $params[$name] = $field->reflection->getValue($island);
        }

        if (($params['sort'] ?? '') === '') {
            unset($params['sort'], $params['direction']);
        }

        $paginator = $configuration->paginator($settings);
        $settings = Settings::read($object, $settings);
        if (!empty($settings['scope'])) {
            throw new InvalidArgumentException('Use the island mount urlPrefix instead of a paginator scope.');
        }

        $settings['allowedParameters'] = array_keys($fields);
        unset($settings['className']);
        $params['page'] = max(1, (int)$params['page']);
        $result = $this->bounded($paginator, $object, $params, $settings);
        $accepted = $this->accept($island, $fields, $result, $params);

        $links = new Links(
            $path,
            $this->query ?? $this->context->request->getUri()->getQuery(),
            new UrlState()->metadata($this->definition, $this->identity->urlPrefix),
            new State()->export($island, $this->definition),
            $this->identity->routing,
        );

        return new Paginated($result, $links, array_keys($fields), [
            'sort' => $accepted['sort'],
            'direction' => $accepted['direction'],
        ]);
    }

    /**
     * Normalize only explicit client paging fields, keeping default database ordering out of sort state.
     *
     * @param array<string, \Interact\Discovery\Property> $fields Compiled writable URL declarations.
     * @param \Cake\Datasource\Paging\PaginatedInterface<int, mixed> $result Native accepted results.
     * @param array<string, mixed> $params Declared incoming paging values.
     * @return array{page: int, limit: int, sort: string, direction: string} Normalized native parameters.
     */
    protected function accept(Island $island, array $fields, PaginatedInterface $result, array $params): array
    {
        $accepted = [
            'page' => $result->currentPage(),
            'limit' => $result->perPage(),
            'sort' => isset($params['sort']) ? (string)$result->pagingParam('sort') : '',
            'direction' => (string)($result->pagingParam('direction') ?? 'asc'),
        ];
        if ($accepted['sort'] === '') {
            $accepted['direction'] = 'asc';
        }

        foreach ($fields as $name => $field) {
            $field->assign($island, $accepted[$name]);
        }

        return $accepted;
    }

    /**
     * Retry an out-of-bounds read once using CakePHP's calculated last page and a fresh query clone.
     *
     * @param array<string, mixed> $params Declared paging state.
     * @param array<string, mixed> $settings Native paginator settings.
     * @return \Cake\Datasource\Paging\PaginatedInterface<int, mixed>
     */
    protected function bounded(
        NumericPaginator $paginator,
        RepositoryInterface|QueryInterface $object,
        array $params,
        array $settings,
    ): PaginatedInterface {
        try {
            return $paginator->paginate(
                $object instanceof QueryInterface ? clone $object : $object,
                $params,
                $settings,
            );
        } catch (PageOutOfBoundsException $pageOutOfBoundsException) {
            $attributes = $pageOutOfBoundsException->getAttributes();
            /** @var array{currentPage: int} $paging */
            $paging = $attributes['pagingParams'];
            $params['page'] = $paging['currentPage'];

            return $paginator->paginate(
                $object instanceof QueryInterface ? clone $object : $object,
                $params,
                $settings,
            );
        }
    }
}
