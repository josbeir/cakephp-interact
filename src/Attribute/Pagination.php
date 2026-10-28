<?php
declare(strict_types=1);

namespace Interact\Attribute;

use Attribute;

/**
 * Declares one typed paging state and its server-owned native paginator settings.
 *
 * Only the declared scalar selection is transported. Exact marker recognition requires
 * this attribute to remain final; subclasses are not declaration markers.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Pagination
{
    /**
     * Configure a listing independently of other pagination properties on the same island.
     *
     * @param array<string, mixed> $settings Native numeric paginator defaults, kept server-side.
     * @param bool|array<string, string> $url Bind all paging fields or only the named aliases.
     * @param bool|list<string> $history Push history for all URL fields or a selected subset.
     * @param bool $editableLimit Allow browser changes to the configured page size.
     * @param array{page?: int, sort?: string, direction?: string} $defaults Initial paging selection.
     */
    public function __construct(
        public array $settings = [],
        public bool|array $url = false,
        public bool|array $history = false,
        public bool $editableLimit = false,
        public array $defaults = [],
    ) {}
}
