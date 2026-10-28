<?php
declare(strict_types=1);

namespace Interact\Discovery;

/**
 * Carries the resolved public name and native rendering target for one island class.
 *
 * These values are compiled from trusted server-side classes and optional Render
 * attributes; browser requests cannot supply template paths or root tags.
 */
readonly class Declaration
{
    /**
     * Retain one resolved name and view selection for rendering and child preservation.
     */
    public function __construct(
        public string $name,
        public ?string $template = null,
        public ?string $element = null,
        public string $tag = 'div',
    ) {}
}
