<?php
declare(strict_types=1);

namespace Interact\View\Helper;

use Cake\Routing\Router;
use Cake\View\Helper\PaginatorHelper as CakePaginatorHelper;
use Cake\View\StringTemplate;
use Closure;
use Interact\Pagination\LinkParameters;
use Interact\Pagination\Paginated;
use InvalidArgumentException;

/**
 * Preserves CakePHP's paginator markup while adapting island-owned link destinations.
 *
 * Applications retain native template configuration and may subclass this helper.
 * Mutable helper state is scoped to each island, including nested renders and failures.
 */
class PaginatorHelper extends CakePaginatorHelper
{
    /**
     * Enter an isolated result and options scope, returning its exception-safe restoration callback.
     */
    public function islandScope(?Paginated $result): Closure
    {
        $previous = [
            $this->paginated,
            $this->config,
            $this->templater instanceof StringTemplate ? clone $this->templater : null,
        ];
        $this->paginated = $result;
        if ($result instanceof Paginated) {
            $this->setConfig('options.url', [], false);
        }

        return function () use ($previous): void {
            [$this->paginated, $this->config, $this->templater] = $previous;
        };
    }

    /**
     * Generate an ordinary page URL and register reactive edits independently of the string templates.
     *
     * Explicit destination overrides retain native CakePHP navigation behavior.
     *
     * @param array<string, mixed> $options Native paging options.
     * @param array $url Native route overrides.
     * @param array<string, mixed> $urlOptions Escaping and full-base options.
     */
    public function generateUrl(array $options = [], array $url = [], array $urlOptions = []): string
    {
        $urlOptions = [
            'escape' => ($urlOptions['escape'] ?? true) !== false,
            'fullBase' => ($urlOptions['fullBase'] ?? false) === true,
        ];
        $result = $this->paginated();
        if (
            !$result instanceof Paginated
            || $url !== []
            || !empty($this->config['options']['url'])
            || !empty($this->config['options']['routePlaceholders'])
        ) {
            if ($result instanceof Paginated) {
                return $this->Url->build(
                    $result->links->routing->complete(parent::generateUrlParams($options, $url)),
                    $urlOptions,
                );
            }

            return parent::generateUrl($options, $url, $urlOptions);
        }

        if (isset($options['sort']) && !in_array('sort', $result->fields, true)) {
            throw new InvalidArgumentException('Paginator sort links require sort and direction URL state.');
        }

        /** @var string $format */
        $format = $this->getConfig('options.sortFormat');
        $destination = $result->links->generate(LinkParameters::read($result, $options, $format), $result->fields);
        if ($urlOptions['fullBase']) {
            $destination = Router::fullBaseUrl() . $destination;
        }

        return $urlOptions['escape']
            ? htmlspecialchars($destination, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : $destination;
    }
}
