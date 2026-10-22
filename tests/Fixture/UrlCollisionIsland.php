<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Attribute\Render;
use Interact\Attribute\State;

/**
 * Intentionally declares overlapping URL capabilities to verify discovery rejects ambiguity.
 */
#[Render(element: 'url')]
class UrlCollisionIsland extends UrlIsland
{
    #[State(writable: true, url: 'q')]
    public string $duplicate = '';
}
