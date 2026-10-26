<?php
declare(strict_types=1);

namespace Interact\Test\Fixture;

/**
 * Supplies declared defaults for the shared PHP and browser query-codec examples.
 * These are property types, not a second runtime schema for application arrays.
 */
class StructuredUrlTypes
{
    public ?string $text = 'start';

    public ?int $number = 7;

    public ?float $ratio = 1.5;

    public ?bool $flag = true;

    /**
     * @var array{status: string, categories: list<string>, price: array{min: string, max: string}}
     */
    public array $filters = ['status' => '', 'categories' => ['default'], 'price' => ['min' => '', 'max' => '100']];

    /**
     * @var list<string>
     */
    public array $items = ['default'];
}
