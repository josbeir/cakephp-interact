<?php
declare(strict_types=1);

namespace Interact\Discovery;

use Cake\Core\Plugin;
use Cake\Utility\Inflector;
use Interact\Attribute\Render;
use InvalidArgumentException;
use ReflectionClass;
use function Cake\Core\pluginSplit;

/**
 * Compiles a native view target from an island's class structure and plugin ownership.
 *
 * Only trusted declaration metadata selects view paths. CakePHP retains responsibility
 * for template lookup, theme precedence, and application overrides.
 */
readonly class Template
{
    public ?string $plugin;

    public Declaration $declaration;

    /**
     * Resolve one declaration independently of the current controller or mount alias.
     *
     * @param \ReflectionClass<\Interact\Island> $reflection Declared island class.
     * @param \Interact\Attribute\Render $declaration Optional native rendering configuration.
     * @param string|null $plugin Plugin owner supplied by class resolution when available.
     * @param string|null $name Resolved public name, or inferred from the class structure.
     * @throws \InvalidArgumentException When no conventional template can be inferred.
     */
    public function __construct(
        ReflectionClass $reflection,
        Render $declaration,
        ?string $plugin = null,
        ?string $name = null,
    ) {
        $this->plugin = $plugin ?? $this->owningPlugin($reflection);
        $this->declaration = $this->resolve($reflection, $declaration, $name);
    }

    /**
     * Resolve manually registered classes against loaded plugin source directories.
     *
     * @param \ReflectionClass<\Interact\Island> $reflection Registered class location.
     */
    protected function owningPlugin(ReflectionClass $reflection): ?string
    {
        $file = str_replace('\\', '/', (string)$reflection->getFileName());
        $owner = null;
        $length = 0;
        foreach (Plugin::getCollection() as $plugin) {
            $root = realpath($plugin->getClassPath());
            if ($root === false) {
                continue;
            }

            $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
            if (strlen($root) > $length && str_starts_with($file, $root)) {
                $owner = $plugin->getName();
                $length = strlen($root);
            }
        }

        return $owner;
    }

    /**
     * Compile one owner-relative native target without changing the declared attribute.
     *
     * @param \ReflectionClass<\Interact\Island> $reflection Class whose namespace supplies the default path.
     */
    protected function resolve(ReflectionClass $reflection, Render $declaration, ?string $name): Declaration
    {
        $template = $declaration->template;
        $element = $declaration->element;
        if ($template === null && $element === null) {
            $relative = Name::relative($reflection, $this->plugin);
            if ($relative === null) {
                throw new InvalidArgumentException(
                    'Infer island templates from an Island namespace, or declare an explicit template or element.',
                );
            }

            $directory = str_replace('\\', '/', substr($relative, 0, -strlen($reflection->getShortName())));
            $template =
                'island/'
                . $directory
                . Inflector::underscore(
                    preg_replace('/Island$/', '', $reflection->getShortName()) ?? $reflection->getShortName(),
                );
        }

        return new Declaration(
            $name ?? Name::fromClass($reflection, $this->plugin),
            template: $template === null ? null : $this->qualifyTarget($template),
            element: $element === null ? null : $this->qualifyTarget($element),
            tag: $declaration->tag,
        );
    }

    /**
     * Preserve CakePHP plugin references and scope other paths to this island's owner.
     */
    protected function qualifyTarget(string $target): string
    {
        [$plugin] = pluginSplit($target);
        if ($this->plugin !== null && ($plugin === null || !Plugin::isLoaded($plugin))) {
            return $this->plugin . '.' . $target;
        }

        return $target;
    }
}
