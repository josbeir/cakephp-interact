<?php
declare(strict_types=1);

namespace Interact\Upload;

use Cake\Container\ContainerInterface;
use Cake\Core\Configure;
use Interact\Http\FailureResponder;
use Interact\Http\UpdateHandler;
use Interact\Runtime\Factory;

/**
 * Registers upload and endpoint collaborators while respecting application replacements.
 */
class UploadServices
{
    /**
     * Supply private staging defaults through CakePHP's normal container boundary.
     */
    public function register(ContainerInterface $container): void
    {
        if (!$container->hasDefinition(UploadConfig::class)) {
            $container->addShared(UploadConfig::class, static function (): UploadConfig {
                /** @var mixed $options */
                $options = Configure::read('Interact.uploads', []);
                assert(is_array($options), 'Upload configuration must be an array.');

                return new UploadConfig($options);
            });
        }

        if (!$container->hasDefinition(UploadStorageInterface::class)) {
            $container->addShared(UploadStorageInterface::class, LocalUploadStorage::class)
                ->addArgument(UploadConfig::class);
        }

        if (!$container->hasDefinition(UploadManager::class)) {
            $container->addShared(UploadManager::class)
                ->addArguments([UploadStorageInterface::class, UploadConfig::class]);
        }

        if (!$container->hasDefinition(UpdateHandler::class)) {
            $container->addShared(UpdateHandler::class)->addArguments([Factory::class, FailureResponder::class]);
        }
    }
}
