<?php
declare(strict_types=1);

namespace Interact\Event;

use Interact\Discovery\Property;
use Interact\Island;
use InvalidArgumentException;

/**
 * Compiles literal listener names and top-level prop placeholders without retaining island instances.
 *
 * Only declared string and integer props can contribute browser-visible routing names.
 * Resolving a name never grants authorization to the receiving action.
 */
readonly class ListenerNames
{
    /** @var array<string, array{actions: list<string>, props: array<string, Property>}> Cached declarations only. */
    protected array $templates;

    /**
     * Validate template syntax and explicit prop ownership while compiling metadata.
     *
     * @param array<string, list<string>> $listeners Templates mapped to declared actions.
     * @param array<string, Property> $properties Declared props and state.
     * @throws InvalidArgumentException When placeholders reference unsupported properties or syntax.
     */
    public function __construct(array $listeners, array $properties)
    {
        $templates = [];
        foreach ($listeners as $template => $actions) {
            $matches = [];
            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $template, $matches);
            BrowserEvent::assertName(str_replace($matches[0], 'x', $template));
            $props = [];
            foreach ($matches[1] as $name) {
                $property = $properties[$name] ?? null;
                if ($property === null || !$property->prop || !in_array($property->type, ['int', 'string'], true)) {
                    throw new InvalidArgumentException(
                        'Listener placeholders require declared string or integer props.',
                    );
                }

                $props[$name] = $property;
            }

            $templates[$template] = ['actions' => $actions, 'props' => $props];
        }

        $this->templates = $templates;
    }

    /**
     * Resolve concrete names from accepted snapshot values or a restored request-local island.
     *
     * @param array<string, mixed>|Island $source Accepted values, never unsent browser drafts.
     * @return array<string, list<string>> Concrete browser event names and their actions.
     * @throws InvalidArgumentException When a prop is unavailable or produces an invalid concrete name.
     */
    public function resolve(array|Island $source): array
    {
        $listeners = [];
        foreach ($this->templates as $template => $declaration) {
            $replacements = [];
            foreach ($declaration['props'] as $key => $property) {
                /** @var mixed $value */
                $value = $source instanceof Island ? $property->read($source) : $source[$key] ?? null;
                if (!is_int($value) && !is_string($value)) {
                    throw new InvalidArgumentException(
                        'Listener placeholder values must be initialized strings or integers.',
                    );
                }

                $replacements['{' . $key . '}'] = (string)$value;
            }

            $name = strtr($template, $replacements);
            BrowserEvent::assertName($name);
            $listeners[$name] = [...($listeners[$name] ?? []), ...$declaration['actions']];
        }

        return $listeners;
    }
}
