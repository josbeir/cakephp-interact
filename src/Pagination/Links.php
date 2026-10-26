<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Interact\Action\RouteContext;
use Interact\Url\ArrayValue;
use Interact\Url\QueryWriter;
use InvalidArgumentException;

/**
 * Maps native paginator parameters to explicitly owned query names and records enhanced links.
 *
 * The original page path comes from signed identity. Unrelated query parameters and
 * application URL state are retained; only declared paging fields become browser edits.
 * Paging fields without URL bindings use opaque fragment targets, requiring JavaScript.
 */
class Links
{
    /**
     * @var array<string, array<string, int|string>> Generated destinations and their scalar state edits.
     */
    protected array $targets = [];

    /**
     * Capture the current query and accepted URL values for one dataset only.
     *
     * @param array<string, array{name: string, type: string, default: mixed, history: bool, nullable?: bool}> $fields Explicit URL declarations.
     * @param array<string, mixed> $values Accepted island state.
     */
    public function __construct(
        public readonly string $path,
        protected readonly string $query,
        protected readonly array $fields,
        protected readonly array $values,
        public readonly RouteContext $routing = new RouteContext(),
    ) {
    }

    /**
     * Build a paging target and remember only its declared paging changes.
     *
     * @param array<string, mixed> $paging Native page, sort, direction, and limit values.
     * @param list<string> $names Conventional declared paging fields.
     */
    public function generate(array $paging, array $names): string
    {
        $values = $this->values;
        $changes = [];
        foreach ($names as $name) {
            /** @var mixed $value */
            $value = $paging[$name] ?? $this->values[$name];
            $changes[$name] = $name === 'page' || $name === 'limit' ? (int)$value : (string)$value;
            $values[$name] = $changes[$name];
        }

        $pairs = [];
        $owned = array_column($this->fields, 'name');
        foreach (explode('&', ltrim($this->query, '?')) as $pair) {
            $key = urldecode(explode('=', $pair, 2)[0]);
            $base = explode('[', $key, 2)[0];
            if ($pair !== '' && !in_array($base, $owned, true)) {
                $pairs[] = $pair;
            }
        }

        foreach ($this->fields as $name => $field) {
            /** @var mixed $value */
            $value = $values[$name];
            $query = ArrayValue::same($value, $field['default'])
                ? ''
                : new QueryWriter()->write($field['name'], $value);
            if ($query !== '') {
                $pairs[] = $query;
            }
        }

        $url = $this->path . ($pairs === [] ? '' : '?' . implode('&', $pairs));
        if (array_diff($names, array_keys($this->fields)) !== []) {
            $url .= '#interact-pagination-' . bin2hex(random_bytes(12));
        }

        $this->targets[$url] = $changes;

        return $url;
    }

    /**
     * Emit inert, escaped runtime metadata without imposing markup on native helper templates.
     */
    public function markup(): string
    {
        if ($this->targets === []) {
            return '';
        }

        return (
            '<template data-interact-pagination="'
                . htmlspecialchars(
                    json_encode($this->targets, JSON_THROW_ON_ERROR),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                )
                . '"></template>'
        );
    }

    /**
     * Require reloadable paging state before generating an alternate native route destination.
     *
     * @param list<string> $names Conventional paging fields used by the result.
     * @throws InvalidArgumentException When a native destination would expose state-only parameters.
     */
    public function assertUrlBound(array $names): void
    {
        if (array_diff($names, array_keys($this->fields)) !== []) {
            throw new InvalidArgumentException('Native paginator URL overrides require URL-bound paging state.');
        }
    }
}
