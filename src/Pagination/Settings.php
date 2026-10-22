<?php
declare(strict_types=1);

namespace Interact\Pagination;

use Cake\Datasource\QueryInterface;
use Cake\Datasource\RepositoryInterface;

/**
 * Resolves native per-repository settings before enforcing an explicit client sorting allowlist.
 * Application configuration remains separate from writable browser parameters.
 */
class Settings
{
    /**
     * Apply native per-repository defaults before enforcing the explicit sort allowlist.
     *
     * @param array<string, mixed> $settings Application-owned configuration.
     * @return array<string, mixed> Effective settings for a single repository.
     */
    public static function read(RepositoryInterface|QueryInterface $object, array $settings): array
    {
        $repository = $object instanceof QueryInterface ? $object->getRepository() : $object;
        $alias = $repository?->getAlias() ?? '';
        if (isset($settings[$alias])) {
            /** @var array<string, mixed> $scoped */
            $scoped = $settings[$alias];
            $settings = $scoped;
        }

        $settings['sortableFields'] ??= [];

        return $settings;
    }
}
