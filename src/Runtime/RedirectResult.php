<?php
declare(strict_types=1);

namespace Interact\Runtime;

/**
 * Carries terminal navigation without rendering or exposing another snapshot.
 * The HTTP update remains JSON so middleware redirects stay distinguishable from action outcomes.
 */
readonly class RedirectResult extends Result
{
    /**
     * Associate the navigation with the verified island identity.
     */
    public function __construct(string $id, public string $destination, public bool $replace = false)
    {
        parent::__construct($id, '', '');
    }

    /**
     * Emit a terminal envelope with no markup, state, or application events.
     *
     * @return array<string, mixed> Validated browser navigation request.
     */
    public function payload(): array
    {
        return [
            'version' => 1,
            'id' => $this->id,
            'redirect' => ['url' => $this->destination, 'replace' => $this->replace],
        ];
    }
}
