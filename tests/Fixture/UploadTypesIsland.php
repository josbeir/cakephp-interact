<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

use Interact\Island;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Supplies real PHP property shapes for upload declaration and value-boundary tests.
 *
 * Invalid members are intentional fixtures; metadata compilation must reject their
 * declaration before they can receive files or participate in snapshot transport.
 */
class UploadTypesIsland extends Island
{
    public ?UploadedFileInterface $constructor = null;

    public ?UploadedFileInterface $single = null;

    public array $multiple = [];

    public string $wrong = '';

    public ?UploadedFileInterface $missing;

    public array $nonempty = ['invalid'];

    protected ?UploadedFileInterface $hidden = null;

    public static ?UploadedFileInterface $shared = null;

    public readonly ?UploadedFileInterface $immutable;
}
