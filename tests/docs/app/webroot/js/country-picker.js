// webroot/js/country-picker.js; load after Tom Select and before Interact's runtime.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('countryPicker', () => {
    let widget;
    return {
      selection: '',
      init() {
        widget = new window.TomSelect(this.$el.querySelector('select'), {
          create: false,
          onChange: (value) => {
            this.selection = value;
          },
        });
        this.$watch('selection', (value) => {
          if (widget.getValue() !== value) widget.setValue(value, true);
        });
      },
      destroy() {
        widget?.destroy();
      },
    };
  });
});
