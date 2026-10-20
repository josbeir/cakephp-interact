<?php
declare(strict_types=1);

namespace TestApp\Island;

use Interact\Attribute\Action;
use Interact\Attribute\AsIsland;
use Interact\Attribute\State;
use Interact\Island;
use InvalidArgumentException;

/**
 * Owns a temporary task collection to demonstrate adding, toggling, and removing keyed rows.
 *
 * Rows remain server-owned state. Typed actions select existing rows, and a writable text
 * draft is validated before it enters the collection; nothing is stored in the database.
 */
#[AsIsland('task-board', element: 'Interact/task_board')]
class TaskBoard extends Island
{
    #[State(writable: true)]
    public string $draft = '';

    /**
     * Stable row identities and their server-owned display state.
     *
     * @var list<array{id: int, text: string, done: bool}>
     */
    #[State]
    public array $tasks = [
        ['id' => 1, 'text' => 'Explore independent islands', 'done' => true],
        ['id' => 2, 'text' => 'Try a conditional form', 'done' => false],
        ['id' => 3, 'text' => 'Build something with CakePHP', 'done' => false],
    ];

    #[State]
    public int $nextId = 4;

    /**
     * Append a bounded, nonempty draft and clear the input after a successful action.
     */
    #[Action]
    public function add(): void
    {
        $text = trim($this->draft);
        if ($text === '' || mb_strlen($text) > 80 || count($this->tasks) >= 12) {
            $this->setErrors(['draft' => ['Use 1 to 80 characters, with up to 12 tasks.']]);

            return;
        }

        $this->tasks[] = ['id' => $this->nextId++, 'text' => $text, 'done' => false];
        $this->draft = '';
    }

    /**
     * Toggle a row selected by its stable identity rather than its current array position.
     */
    #[Action]
    public function toggle(int $id): void
    {
        $index = $this->indexOf($id);
        $this->tasks[$index]['done'] = !$this->tasks[$index]['done'];
    }

    /**
     * Remove an existing row while retaining the identities of its surviving neighbors.
     */
    #[Action]
    public function remove(int $id): void
    {
        array_splice($this->tasks, $this->indexOf($id), 1);
    }

    /**
     * Count completed rows for a computed progress summary.
     *
     * @return array<string, mixed> Progress derived from the current collection.
     */
    public function viewData(): array
    {
        return ['completed' => count(array_filter($this->tasks, static fn(array $task): bool => $task['done']))];
    }

    /**
     * Reject action arguments that do not identify a row in this island's current collection.
     */
    protected function indexOf(int $id): int
    {
        foreach ($this->tasks as $index => $task) {
            if ($task['id'] === $id) {
                return $index;
            }
        }

        throw new InvalidArgumentException('That task is no longer available.');
    }
}
