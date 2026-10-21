import { registerEnhancedSelect } from './enhanced-select';

if (window.Alpine) registerEnhancedSelect(window.Alpine);
else
  document.addEventListener(
    'alpine:init',
    () => {
      if (window.Alpine) registerEnhancedSelect(window.Alpine);
    },
    { once: true },
  );
