<?php
declare(strict_types=1);

namespace Interact\Bake;

use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Utility\Inflector;
use Interact\Discovery\Name;
use InvalidArgumentException;
use function Cake\Core\pluginSplit;

/**
 * Resolves a scaffold's conventional class, template, and mount names before any files are written.
 *
 * Plugin ownership is explicit and must agree with the command's target option.
 * Namespace groups follow the same normalization as runtime island discovery.
 */
readonly class IslandName
{
    public ?string $plugin;

    public string $name;

    public string $relative;

    /**
     * Validate a name and optional plugin target without accepting arbitrary output paths.
     *
     * @throws \InvalidArgumentException When names or plugin targets are invalid or conflicting.
     */
    public function __construct(string $input, ?string $plugin = null)
    {
        [$namedPlugin, $relative] = pluginSplit(Name::normalize($input));
        if ($namedPlugin !== null && $plugin !== null && $namedPlugin !== $plugin) {
            throw new InvalidArgumentException('The island name and --plugin must target the same plugin.');
        }

        $this->plugin = $namedPlugin ?? $plugin;
        $this->relative = preg_replace('/Island$/', '', $relative) ?? $relative;
        $this->name = Name::normalize(($this->plugin === null ? '' : $this->plugin . '.') . $this->relative);
        if ($this->plugin !== null && !Plugin::isLoaded($this->plugin)) {
            throw new InvalidArgumentException(sprintf(
                'Load the %s plugin before baking an island into it.',
                $this->plugin,
            ));
        }
    }

    /**
     * Preserve namespace groups beneath the configured application or plugin's Island namespace.
     */
    public function namespace(): string
    {
        $base = $this->plugin ?? (string)Configure::read('App.namespace', 'App');
        $directory = dirname($this->relative);

        return str_replace('/', '\\', $base . '/Island' . ($directory === '.' ? '' : '/' . $directory));
    }

    /**
     * Underscore only the final template name, preserving namespace directory casing.
     */
    public function template(): string
    {
        $directory = dirname($this->relative);

        return ($directory === '.' ? '' : $directory . '/') . Inflector::underscore(basename($this->relative)) . '.php';
    }
}
