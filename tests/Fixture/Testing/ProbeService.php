<?php
declare(strict_types=1);

namespace Interact\Test\Fixture\Testing;

/**
 * Observes fresh island construction and native rendering across fluent test steps.
 * Application container mocks can replace the presentation marker without replacing island behavior.
 */
class ProbeService
{
    public int $constructions = 0;

    public int $renders = 0;

    /**
     * @var list<\Cake\Http\ServerRequest> Request contexts observed by application authorization.
     */
    public array $requests = [];

    /**
     * Supply a visible marker for dependency-injection assertions.
     */
    public function __construct(public string $marker = 'real service')
    {
    }
}
