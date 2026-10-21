<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Dom\Attr;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Describes bound controls independently of CakePHP's generated wrappers and hidden fields.
 *
 * Documentation tests use this contract to compare plain HTML and native helper output.
 */
class DocumentationControls
{
    /**
     * Compare interactive control semantics while allowing helper-owned wrappers and hidden inputs.
     *
     * @return list<array{tag: string, type: string, binding: array<string, string>, values: list<string>}>
     */
    public function collect(string $html): array
    {
        $document = HTMLDocument::createFromString(
            '<!doctype html><html><body>' . $html . '</body></html>',
            overrideEncoding: 'UTF-8',
        );
        $controls = [];
        foreach ($document->querySelectorAll('input, textarea, select, form') as $element) {
            assert($element instanceof Element, 'Control selectors return elements.');
            $binding = [];
            foreach ($element->attributes as $attribute) {
                assert($attribute instanceof Attr, 'An element exposes attribute nodes.');
                if (str_starts_with($attribute->name, 'x-interact:') || $attribute->name === ':value') {
                    $binding[$attribute->name] = str_replace('"', "'", $attribute->value);
                }
            }

            if ($binding === []) {
                continue;
            }

            $values = [];
            foreach ($element->querySelectorAll('option') as $option) {
                assert($option instanceof Element, 'Option selectors return elements.');
                $values[] = $option->getAttribute('value') ?? '';
            }

            $type = $element->getAttribute('type') ?? '';
            if (
                $type === 'radio'
                || $type === 'checkbox'
                && str_ends_with($element->getAttribute('name') ?? '', '[]')
            ) {
                $values[] = $element->getAttribute('value') ?? '';
            }

            $controls[] = ['tag' => $element->localName, 'type' => $type, 'binding' => $binding, 'values' => $values];
        }

        return $controls;
    }
}
