<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Overrides an island's conventional native view or root HTML tag.
 *
 * Metadata compilation recognizes this exact marker class; subclassed attributes are not supported.
 * It remains final to make that declaration boundary explicit.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Render
{
    /**
     * Infer a native island template unless a template or element is explicitly selected.
     */
    public function __construct(
        public ?string $template = null,
        public ?string $element = null,
        public string $tag = 'div',
    ) {
        if ($template !== null && $element !== null) {
            throw new InvalidArgumentException('Declare at most one template or element.');
        }

        if (!in_array(
            $tag,
            ['div', 'section', 'article', 'aside', 'main', 'nav', 'header', 'footer', 'fieldset'],
            true,
        )) {
            throw new InvalidArgumentException('Declare a supported non-void island root tag.');
        }
    }
}
