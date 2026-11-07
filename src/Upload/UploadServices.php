<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\Core\Configure;
use Cake\Core\ContainerInterface;
use Interact\Command\CleanupUploadsCommand;
use Interact\Container\Services;
use InvalidArgumentException;

/**
 * Registers upload collaborators while respecting application replacements.
 */
class UploadServices
{
    /**
     * Supply private staging defaults through CakePHP's normal container boundary.
     */
    public function register(ContainerInterface $container): void
    {
        if (!Services::has($container, UploadConfig::class)) {
            $container->addShared(UploadConfig::class, static function (): UploadConfig {
                /** @var mixed $options */
                $options = Configure::read('Interact.uploads', []);
                if (!is_array($options)) {
                    throw new InvalidArgumentException('Upload configuration must be an array.');
                }

                return new UploadConfig($options);
            });
        }

        if (!Services::has($container, UploadStorageInterface::class)) {
            $container->addShared(UploadStorageInterface::class, LocalUploadStorage::class)
                ->addArgument(UploadConfig::class);
        }

        if (!Services::has($container, UploadManager::class)) {
            $container->addShared(UploadManager::class)
                ->addArguments([UploadStorageInterface::class, UploadConfig::class]);
        }

        if (!Services::has($container, CleanupUploadsCommand::class)) {
            $container->add(CleanupUploadsCommand::class)->addArgument(UploadStorageInterface::class);
        }
    }
}
