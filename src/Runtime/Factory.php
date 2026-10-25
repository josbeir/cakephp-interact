<?php
declare(strict_types=1);

namespace Interact\Runtime;

use Cake\View\View;
use Interact\Configuration;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Realtime\Subscriptions;
use Interact\Rendering\PhpRenderer;
use Interact\Security\SnapshotCodec;
use Interact\Upload\UploadManager;
use Interact\View\Helper\InteractHelper;
use Interact\View\TemplateView;
use ReflectionClass;

/**
 * Creates the rendering collaborators used by one request's islands.
 *
 * The native view helper shares the runtime's nested render stack so child ownership is
 * consistent. Override createView() to supply an application TemplateView with custom helpers.
 */
class Factory
{
    /**
     * Reuse immutable signing configuration and compiled declaration metadata, not island instances.
     */
    public function __construct(
        protected readonly Registry $registry,
        protected readonly SnapshotCodec $snapshots,
        protected readonly UploadManager $uploads = new UploadManager(),
        protected readonly Configuration $configuration = new Configuration(),
        protected readonly ?Subscriptions $subscriptions = null,
    ) {
    }

    /**
     * Create the runtime before configuring its helper to avoid recursive construction.
     */
    public function create(View $host, IslandContext $context): Runtime
    {
        $view = $this->createView($host);
        $runtime = new Runtime($this->registry, $this->snapshots, new PhpRenderer($view), uploads: $this->uploads);
        $runtime->attachSubscriptions($this->subscriptions);
        if ($view->helpers()->has('Interact')) {
            $helper = $view->helpers()->get('Interact');
            assert(
                $helper instanceof InteractHelper,
                'The configured collaborator must satisfy its declared contract.',
            );
            $helper->attach($runtime, $context);
        } else {
            $view->loadHelper('Interact', [
                'className' => InteractHelper::class,
                'runtime' => $runtime,
                'context' => $context,
                'autoScript' => false,
                'document' => false,
            ]);
        }

        return $runtime;
    }

    /**
     * Override to use an application TemplateView subclass with additional helpers.
     */
    protected function createView(View $host): TemplateView
    {
        return (
            $host instanceof $this->configuration->viewClass
                ? $host
                : new ReflectionClass($this->configuration->viewClass)->newInstance(
                    $host->getRequest(),
                    $host->getResponse(),
                    viewOptions: ['theme' => $host->getTheme()],
                )
        );
    }
}
