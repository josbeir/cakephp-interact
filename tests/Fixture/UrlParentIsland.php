<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Render;

/**
 * Provides a distinct parent alias so nested URL mounts can use the real render stack.
 */
#[Render(element: 'url')]
class UrlParentIsland extends UrlIsland
{
}
