<?php
declare(strict_types=1);

namespace Interact;

use Cake\Core\Configure;
use Interact\Configuration\AssetSettings;
use Interact\Configuration\SnapshotSettings;
use Interact\Runtime\Values;
use Interact\Security\SnapshotCodec;
use Interact\View\TemplateView;
use InvalidArgumentException;
use ReflectionClass;

/**
 * Resolves optional application settings at service and view construction boundaries.
 *
 * Signing secrets stay server-side, browser timing stays in JavaScript, and
 * application helper registration belongs to the selected view class.
 *
 * @consistent-constructor
 */
readonly class Configuration
{
    public int $snapshotTtl;

    protected SnapshotSettings $signing;

    /**
     * @var class-string<\Interact\View\TemplateView>
     */
    public string $viewClass;

    public string|false $script;

    /**
     * @var array<string, mixed> Native HtmlHelper options, including the selected block.
     */
    public array $scriptOptions;

    public string $scriptBlock;

    /**
     * Validate new setting groups while leaving existing upload and navigation settings intact.
     *
     * @param array<string, mixed> $options Application Interact configuration.
     * @throws \InvalidArgumentException When a setting group, value, or view class is invalid.
     */
    public function __construct(array $options = [])
    {
        $this->signing = new SnapshotSettings(static::group($options, 'snapshots', ['ttl', 'key']));
        $this->snapshotTtl = $this->signing->ttl;
        $view = static::group($options, 'view', ['className']);
        /** @var mixed $class */
        $class = array_key_exists('className', $view) ? $view['className'] : TemplateView::class;
        if (
            !is_string($class)
            || !is_a($class, TemplateView::class, true)
            || !new ReflectionClass($class)->isInstantiable()
        ) {
            throw new InvalidArgumentException('Interact view.className must be a concrete TemplateView class.');
        }

        $this->viewClass = $class;
        $assets = new AssetSettings(static::group($options, 'assets', ['script', 'scriptOptions']));
        $this->script = $assets->script;
        $this->scriptOptions = $assets->options;
        $this->scriptBlock = $assets->block;
    }

    /**
     * Read application configuration only at an explicit composition boundary.
     *
     * @throws \InvalidArgumentException When the application configuration is malformed.
     */
    public static function read(): static
    {
        /** @var mixed $options */
        $options = Configure::read('Interact', []);
        if (!is_array($options)) {
            throw new InvalidArgumentException('Interact configuration must be an array.');
        }

        return new static(Values::map($options));
    }

    /**
     * Construct the default signer without exposing its secret in browser configuration.
     */
    public function snapshots(): SnapshotCodec
    {
        return $this->signing->codec();
    }

    /**
     * Validate a configuration record and optionally reject unknown option names.
     *
     * @param array<string, mixed> $options Parent configuration record.
     * @param list<string>|null $keys Allowed option names; null permits native helper options.
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When the record or its option names are invalid.
     */
    public static function group(array $options, string $name, ?array $keys = null): array
    {
        /** @var mixed $value */
        $value = array_key_exists($name, $options) ? $options[$name] : [];
        if (
            !is_array($value)
            || $keys !== null
            && array_diff(array_keys($value), $keys) !== []
        ) {
            throw new InvalidArgumentException(sprintf('Invalid Interact configuration group "%s".', $name));
        }

        return Values::map($value);
    }
}
