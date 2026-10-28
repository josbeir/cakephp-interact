<?php
declare(strict_types=1);

namespace Interact\Loading;

use Interact\Discovery\Declaration;
use Interact\View\TemplateView;
use LogicException;
use function Cake\I18n\__;

/**
 * Renders lightweight loading content without calling island rendering hooks.
 *
 * Custom elements use the configured native view with only declared mount props.
 * The default supplies translated feedback and explicit recovery without application setup.
 */
class PlaceholderRenderer
{
    /**
     * Receive the application's isolated view when custom elements are supported.
     */
    public function __construct(protected readonly ?TemplateView $view = null) {}

    /**
     * Render an explicit element or the accessible built-in loading controls.
     *
     * @param array<string, mixed> $props Validated declared props, including PHP defaults.
     */
    public function render(Options $options, array $props): string
    {
        if ($options->placeholder !== null) {
            if (!$this->view instanceof TemplateView) {
                throw new LogicException('Configure a placeholder renderer with a TemplateView for custom elements.');
            }

            return $this->view->island(new Declaration('placeholder', element: $options->placeholder), $props);
        }

        $label = $options->mode === 'manual' ? __('Load content') : __('Loading content…');
        $html = '<p role="status" x-show="!$server.error">' . htmlspecialchars($label) . '</p>';
        if ($options->mode === 'manual') {
            $html .=
                '<button type="button" @click="$server.sync()" :disabled="$server.busy" '
                . 'x-show="!$server.error">'
                . htmlspecialchars(__('Load content'))
                . '</button>';
        }

        return (
            $html
                . '<p role="alert" x-text="$server.error ? $server.error.message : \'\'"></p>'
                . '<button type="button" x-show="$server.error &amp;&amp; $server.error.recovery === \'retry\'" '
                . '@click="$server.sync()" :disabled="$server.busy">'
                . htmlspecialchars(__('Try again'))
                . '</button>'
                . '<button type="button" x-show="$server.error &amp;&amp; $server.error.recovery === \'reload\'" '
                . '@click="window.location.reload()">'
                . htmlspecialchars(__('Reload page'))
                . '</button>'
                . '<noscript>'
                . htmlspecialchars(__('Enable JavaScript to load this content.'))
                . '</noscript>'
        );
    }
}
