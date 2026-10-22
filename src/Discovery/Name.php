<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Cake\Core\Configure;
use Cake\Utility\Inflector;
use InvalidArgumentException;
use ReflectionClass;
use function Cake\Core\pluginSplit;

/**
 * Normalizes CakePHP-style island names and derives names from conventional classes.
 *
 * Public selectors are bounded namespace paths rather than arbitrary PHP class names.
 * The Island suffix identifies classes while remaining absent from render names.
 */
class Name
{
    /**
     * Normalize a plugin-qualified name without accepting filesystem traversal or FQCNs.
     *
     * @throws \InvalidArgumentException When the selector is not a conventional island name.
     */
    public static function normalize(string $name): string
    {
        [$plugin, $path] = pluginSplit($name);
        $pattern = '/^[a-zA-Z][a-zA-Z0-9_-]*(?:\/[a-zA-Z][a-zA-Z0-9_-]*)*$/D';
        if (!preg_match($pattern, $path) || ($plugin !== null && !preg_match($pattern, $plugin))) {
            throw new InvalidArgumentException('Use an island name such as Article/Editor or Commerce.Article/Editor.');
        }

        $segments = array_map(
            static fn(string $segment): string => Inflector::camelize(str_replace('-', '_', $segment)),
            explode('/', $path),
        );

        return ($plugin === null ? '' : $plugin . '.') . implode('/', $segments);
    }

    /**
     * Derive a class-relative name, including support for explicitly registered fixture classes.
     *
     * @param \ReflectionClass<\Interact\Island> $reflection Island class being compiled.
     */
    public static function fromClass(ReflectionClass $reflection, ?string $plugin): string
    {
        $relative = static::relative($reflection, $plugin) ?? $reflection->getShortName();
        if (str_ends_with($relative, 'Island')) {
            $relative = substr($relative, 0, -strlen('Island'));
        }

        return ($plugin === null ? '' : $plugin . '.') . str_replace('\\', '/', $relative);
    }

    /**
     * Locate the Island namespace beneath the configured application or owning plugin.
     *
     * @param \ReflectionClass<\Interact\Island> $reflection Island class being compiled.
     */
    public static function relative(ReflectionClass $reflection, ?string $plugin): ?string
    {
        $base = $plugin ?? (string)Configure::read('App.namespace', 'App');
        $prefix = str_replace('/', '\\', $base) . '\\Island\\';
        if (str_starts_with($reflection->getName(), $prefix)) {
            return substr($reflection->getName(), strlen($prefix));
        }

        $position = strpos($reflection->getName(), '\\Island\\');

        return $position === false ? null : substr($reflection->getName(), $position + strlen('\\Island\\'));
    }
}
