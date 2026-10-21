<?php
declare(strict_types=1);

namespace Interact\Action;

use Cake\Http\ServerRequest;
use InvalidArgumentException;

/**
 * Retains the mounting page's routing defaults independently of the update controller.
 * Only validated route names are signed; they never establish access to a destination.
 *
 * @consistent-constructor
 */
readonly class RouteContext
{
    /**
     * @var array<string, string|null> Controller, action, plugin, and prefix defaults.
     */
    public array $defaults;

    /**
     * Validate signed route defaults without accepting arbitrary request attributes.
     */
    public function __construct(mixed $defaults = [])
    {
        if (!is_array($defaults) || array_diff(array_keys($defaults), [
            'controller',
            'action',
            'plugin',
            'prefix',
        ]) !== []) {
            throw new InvalidArgumentException('Invalid signed routing context.');
        }

        $validated = [];
        /** @var mixed $value */
        foreach ($defaults as $key => $value) {
            if (!is_string($key) || ($value !== null && !is_string($value))) {
                throw new InvalidArgumentException('Invalid signed routing default.');
            }

            $validated[$key] = $value;
        }

        $this->defaults = $validated;
    }

    /**
     * Capture only defaults used by CakePHP's reverse routing.
     */
    public static function fromRequest(ServerRequest $request): static
    {
        $defaults = array_intersect_key(
            $request->getAttribute('params', []),
            array_flip([
                'controller',
                'action',
                'plugin',
                'prefix',
            ]),
        );

        return new static(array_map(static fn(mixed $value): mixed => $value === false ? null : $value, $defaults));
    }

    /**
     * Complete partial arrays before the Router can inherit the AJAX endpoint's defaults.
     *
     * @param array<array-key, mixed> $url Application-supplied route arguments.
     *
     * @return array<array-key, mixed> Explicit route arguments for CakePHP.
     */
    public function complete(array $url): array
    {
        if (isset($url['_name']) || isset($url['_path'])) {
            return $url;
        }

        $controller = $this->defaults['controller'] ?? null;
        $action = empty($url['controller']) || $url['controller'] === $controller
            ? $this->defaults['action'] ?? 'index'
            : 'index';

        return $url
            + [
                'controller' => $controller,
                'action' => $action,
                'plugin' => $this->defaults['plugin'] ?? null,
                'prefix' => $this->defaults['prefix'] ?? false,
            ];
    }
}
