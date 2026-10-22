<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Interact\Action\RouteContext;

/**
 * Maps native paginator parameters to explicitly owned query names and records enhanced links.
 *
 * The original page path comes from signed identity. Unrelated query parameters and
 * application URL state are retained; only declared paging fields become browser edits.
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
     * @param array<string, array{name: string, type: string, default: mixed, history: bool}> $fields Explicit URL declarations.
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
     * Build a native GET destination and remember only its declared paging changes.
     *
     * @param array<string, mixed> $paging Native page, sort, direction, and limit values.
     * @param list<string> $names Conventional declared paging fields.
     */
    public function generate(array $paging, array $names): string
    {
        $values = $this->values;
        $changes = [];
        foreach ($names as $name) {
            $field = $this->fields[$name];
            /** @var mixed $value */
            $value = $paging[$name] ?? $field['default'];
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
            if ($value === $field['default']) {
                continue;
            }

            $query = $value === []
                ? rawurlencode($field['name']) . '='
                : http_build_query([$field['name'] => $value], encoding_type: PHP_QUERY_RFC3986);
            if ($query !== '') {
                $pairs[] = $query;
            }
        }

        $url = $this->path . ($pairs === [] ? '' : '?' . implode('&', $pairs));
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
}
