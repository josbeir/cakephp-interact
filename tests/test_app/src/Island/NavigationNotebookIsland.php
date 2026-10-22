<?php
declare(strict_types=1);

namespace TestApp\Island;

use Cake\Http\Exception\ForbiddenException;
use Interact\Attribute\Action;
use Interact\Attribute\Prop;
use Interact\Attribute\State;
use Interact\Island;
use Interact\IslandContext;

/**
 * Keeps an explicit layout draft alive while page content changes around it.
 * Authorization still runs for each destination mount and every subsequent action.
 */
class NavigationNotebookIsland extends Island
{
    #[Prop]
    public string $edition = 'shared';

    #[State(writable: true)]
    public string $note = '';

    #[State]
    public int $saved = 0;

    #[State(writable: true, url: 'notebook', history: true)]
    public string $filter = '';

    /**
     * Keep a browser's existing signed snapshot subject to current session permissions.
     */
    public function authorize(IslandContext $context): void
    {
        /** @var mixed $identity */
        $identity = $context->request->getAttribute('identity');
        if (!is_array($identity) || ($identity['role'] ?? null) !== 'editor') {
            throw new ForbiddenException('Notebook access requires an editor.');
        }
    }

    /**
     * Count explicit application interactions without implying database persistence.
     */
    #[Action]
    public function remember(): void
    {
        $this->saved++;
    }

    /**
     * Request the same enhanced navigation used by ordinary marked links.
     */
    #[Action]
    public function openDetails(): void
    {
        $this->redirect('/navigation/details', navigate: true);
    }
}
