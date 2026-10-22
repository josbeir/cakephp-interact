<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Interact\Discovery\Definition;
use Interact\Island;
use InvalidArgumentException;

/**
 * Resolves action rendering preferences without hiding current or previously rendered errors.
 * Signed feedback metadata records presentation history, never application validation rules.
 */
class RenderPolicy
{
    /**
     * Read optional signed feedback metadata while accepting snapshots from before this feature.
     *
     * @param array<string, mixed> $data Verified snapshot data.
     */
    public static function renderedErrors(array $data): bool
    {
        /** @var mixed $value */
        $value = $data['renderedErrors'] ?? false;
        if (!is_bool($value) || array_key_exists('renderedErrors', $data) && $data['renderedErrors'] === null) {
            throw new InvalidArgumentException('Invalid signed validation feedback marker.');
        }

        return $value;
    }

    /**
     * Only successful actions without outstanding HTML feedback can skip rendering.
     */
    public function skips(Island $island, Definition $definition, SnapshotData $data, ?string $action): bool
    {
        return (
            $action !== null
                && $island->errors() === []
                && !$data->renderedErrors
                && ($definition->renderlessActions[$action] || $island->actionEffects()->skipsRender())
        );
    }
}
