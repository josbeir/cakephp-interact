<?php
declare(strict_types=1);

namespace Interact\View\Helper;

use Cake\Routing\Router;
use Cake\Utility\Security;
use Cake\View\Helper;
use Cake\View\Helper\HtmlHelper;
use Interact\Discovery\Registry;
use Interact\IslandContext;
use Interact\Loading\Options;
use Interact\Navigation\DocumentMiddleware;
use Interact\Runtime\Factory;
use Interact\Runtime\Runtime;
use Interact\Security\SnapshotCodec;

/**
 * Mounts named islands inside normal CakePHP views and manages their browser bootstrap.
 *
 * Nested mounts share a request runtime. Explicit props and stable keys define child
 * identity; applications that own Alpine disable autoScript and install the npm plugin.
 *
 * @extends \Cake\View\Helper<\Cake\View\View>
 */
class InteractHelper extends Helper
{
    /**
     * @var array<string, mixed> Explicit integration overrides; npm users disable autoScript.
     */
    protected array $defaultConfig = [
        'autoScript' => true,
        'scope' => 'default',
        'endpoint' => null,
        'document' => true,
    ];

    protected ?Runtime $runtime = null;

    protected ?IslandContext $context = null;

    /**
     * Configure the helper and optionally enqueue the standalone browser bundle.
     *
     * @param array<string, mixed> $config Services remain view configuration, never template variables.
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        if ($this->getConfig('document') === true) {
            $this->getView()->append('script', DocumentMiddleware::PLACEHOLDER);
        }

        if ($this->getConfig('autoScript') === true) {
            $html = $this->getView()->loadHelper('Html');
            assert($html instanceof HtmlHelper, 'The configured collaborator must satisfy its declared contract.');
            $html->script('Interact.runtime', ['block' => 'script', 'defer' => true]);
        }
    }

    // Approved exception: named loading options keep ordinary mount calls readable.
    // @mago-expect lint:excessive-parameter-list
    /**
     * Mount a named island using explicit props and a stable sibling key.
     *
     * Loading accepts eager (default), lazy, defer, or manual; placeholder names a native element.
     * Repeated children need distinct keys; changing their props requires a new key.
     *
     * ```php
     * echo $this->Interact->render('Article/Editor', props: ['articleId' => 7], key: 'article-7');
     * ```
     *
     * @param string $alias CakePHP-style application/plugin name or an island class reference.
     * @param array<string, mixed> $props Server-owned parameters declared with Prop.
     */
    public function render(
        string $alias,
        array $props = [],
        ?string $key = null,
        ?string $urlPrefix = null,
        string $loading = 'eager',
        ?string $placeholder = null,
    ): string {
        return $this->runtime()->mount(
            $alias,
            $props,
            $key,
            $this->context(),
            new Options($loading, $placeholder, $urlPrefix),
        )->html;
    }

    /**
     * Attach an explicitly constructed request runtime when rendering through the same page view.
     */
    public function attach(Runtime $runtime, IslandContext $context): void
    {
        $this->runtime = $runtime;
        $this->context = $context;
    }

    /**
     * Reuse one runtime for this helper and all its nested renders.
     */
    protected function runtime(): Runtime
    {
        if ($this->runtime instanceof Runtime) {
            return $this->runtime;
        }

        /** @var mixed $runtime */
        $runtime = $this->getConfig('runtime');
        if ($runtime instanceof Runtime) {
            return $this->runtime = $runtime;
        }

        /** @var mixed $factory */
        $factory = $this->getConfig('factory') ?? $this->getView()->getConfig('interactFactory');
        if (!$factory instanceof Factory) {
            $factory = new Factory(new Registry(), new SnapshotCodec(Security::getSalt()));
        }

        return $this->runtime = $factory->create($this->getView(), $this->context());
    }

    /**
     * Resolve the server-configured endpoint without consulting browser state.
     */
    protected function context(): IslandContext
    {
        if ($this->context instanceof IslandContext) {
            return $this->context;
        }

        /** @var mixed $context */
        $context = $this->getConfig('context');
        if ($context instanceof IslandContext) {
            return $this->context = $context;
        }

        /** @var mixed $endpoint */
        $endpoint = $this->getConfig('endpoint');

        return $this->context = new IslandContext(
            $this->getView()->getRequest(),
            (string)$this->getConfig('scope'),
            is_string($endpoint) ? $endpoint : Router::url(['_name' => 'interact:update']),
        );
    }
}
