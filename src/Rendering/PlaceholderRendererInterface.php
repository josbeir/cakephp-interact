<?php
declare(strict_types=1);

namespace Interact\Rendering;

use Interact\Loading\Options;

/**
 * Lets custom renderers supply loading content without running normal island templates.
 */
interface PlaceholderRendererInterface
{
    /**
     * Render loading markup with only the validated mount props.
     *
     * @param array<string, mixed> $props Declared props including defaults.
     */
    public function placeholder(Options $options, array $props): string;
}
