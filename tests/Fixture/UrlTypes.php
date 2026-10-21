<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

/**
 * Supplies invalid declaration shapes without exposing them as application islands.
 */
class UrlTypes
{
    public string $text = '';

    public ?string $nullable = null;

    public string $uninitialized;

    /**
     * @var list<list<string>> Deliberately unsupported nested URL values.
     */
    public array $nested = [['value']];

    /**
     * @var list<int> Deliberately unsupported numeric URL list.
     */
    public array $numbers = [1];

    /**
     * @var array{key: string} Deliberately unsupported keyed URL values.
     */
    public array $record = ['key' => 'value'];

    public int $unsafe = PHP_INT_MAX;
}
