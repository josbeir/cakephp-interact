import Alpine from '@alpinejs/csp';
import { createInteract } from '../../../../resources/ts/index';

Alpine.plugin(
  createInteract({
    defaults: { debounce: 400, pollInterval: 10000, loadingDelay: 300 },
  }),
);
window.Alpine = Alpine;
Alpine.start();
