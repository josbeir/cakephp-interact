<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Runtime;

use Interact\Attribute\Render;
use Interact\Island;

/**
 * Exercises default lifecycle behavior without adding application-specific state or actions.
 */
#[Render(template: 'unused')]
class PlainIsland extends Island {}
