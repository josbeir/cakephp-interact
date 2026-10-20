import { registerEnhancedSelect } from './enhanced-select';

document.addEventListener('alpine:init', () => {
  if (!window.Alpine)
    throw new Error(
      'Load the Interact runtime after the widget registration script.',
    );
  registerEnhancedSelect(window.Alpine);
});
