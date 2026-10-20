import type { Alpine } from 'alpinejs';
import TomSelect from 'tom-select/base';

/**
 * Register the application's select wrapper before Alpine starts.
 *
 * The library instance stays outside reactive state; only its JSON value is
 * modelable. Silent writes prevent server resets from becoming user changes.
 */
export function registerEnhancedSelect(Alpine: Alpine): void {
  Alpine.data('enhancedSelect', () => {
    let widget: TomSelect | undefined;
    return {
      value: '',
      init() {
        const select = this.$el.querySelector('select');
        if (!select)
          throw new Error('The enhanced select requires a select element.');
        this.value = select.value;
        widget = new TomSelect(select, {
          create: false,
          refreshThrottle: 0,
          onChange: (value: unknown) => {
            if (typeof value === 'string') this.value = value;
          },
        });
        widget.control_input.setAttribute(
          'aria-describedby',
          'widget-country-error',
        );
        this.$watch('value', (value: string) => {
          if (widget && widget.getValue() !== value)
            widget.setValue(value, true);
        });
        this.$watch('$server.errors.country', (messages: unknown) => {
          widget?.control_input.setAttribute(
            'aria-invalid',
            Array.isArray(messages) && messages.length ? 'true' : 'false',
          );
        });
      },
      destroy() {
        widget?.destroy();
        widget = undefined;
      },
    };
  });
}
