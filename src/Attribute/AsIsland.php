<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Names an island and its application-owned template or element for discovery.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsIsland
{
    /**
     * Declare exactly one native template or element; browser requests only carry the alias.
     */
    public function __construct(
        public string $name,
        public ?string $template = null,
        public ?string $element = null,
        public string $tag = 'div',
    ) {
        if (!preg_match('/^[a-z][a-z0-9_.-]*$/D', $name) || ($template === null) === ($element === null)) {
            throw new InvalidArgumentException('Declare a valid island alias and exactly one template or element.');
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
