<?php
declare(strict_types=1);

namespace Interact\Configuration;

use Interact\Configuration;
use InvalidArgumentException;

/**
 * Normalizes automatic asset inclusion while retaining native HtmlHelper options.
 * The selected block also carries navigation metadata when scripts are application-owned.
 */
readonly class AssetSettings
{
    public string|false $script;

    public string $block;

    /**
     * @var array<string, mixed> Options passed to the native HtmlHelper.
     */
    public array $options;

    /**
     * Accept a routed asset name or URL, or disable automatic inclusion with false.
     *
     * @param array<string, mixed> $options Asset settings from application configuration.
     * @throws \InvalidArgumentException When automatic inclusion or its output block is invalid.
     */
    public function __construct(array $options = [])
    {
        /** @var mixed $script */
        $script = array_key_exists('script', $options) ? $options['script'] : 'Interact.runtime';
        if ($script !== false && (!is_string($script) || $script === '')) {
            throw new InvalidArgumentException('Interact assets.script must be a nonempty string or false.');
        }

        $this->script = $script;
        $attributes = Configuration::group($options, 'scriptOptions');
        /** @var mixed $block */
        $block = array_key_exists('block', $attributes) ? $attributes['block'] : 'script';
        if ($block === true) {
            $block = 'script';
        }

        if (!is_string($block) || $block === '') {
            throw new InvalidArgumentException('Interact assets.scriptOptions.block must name a view block.');
        }

        $this->block = $block;
        $this->options = ['block' => $block] + $attributes + ['defer' => true];
    }
}
