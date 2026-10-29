import { path } from '../state/paths';

/**
 * A validated writable path and its explicit server synchronization policy.
 */
export type ModelBinding = {
  path: string;
  timing: 'defer' | 'live' | 'blur' | 'change';
  delay: number;
  /** Whether the directive explicitly requests debounce, including for selections. */
  explicitDebounce: boolean;
};

/**
 * Parse model modifiers without evaluating the writable path as JavaScript.
 *
 * Only live bindings accept debounce configuration; conflicting or misspelled
 * modifiers fail at initialization instead of silently changing request timing.
 */
export function modelBinding(
  expression: string,
  modifiers: string[],
  debounce = 250,
): ModelBinding {
  path(expression);
  let timing: ModelBinding['timing'] = 'defer';
  let delay = debounce;
  const seen = new Set<string>();
  for (let index = 0; index < modifiers.length; index++) {
    const modifier = modifiers[index];
    if (modifier === undefined || seen.has(modifier))
      throw new Error('Invalid x-interact:model modifiers.');
    seen.add(modifier);
    if (modifier === 'live' || modifier === 'blur' || modifier === 'change') {
      if (timing !== 'defer')
        throw new Error('Choose one x-interact:model synchronization mode.');
      timing = modifier;
    } else if (modifier === 'debounce') {
      const duration = modifiers[index + 1];
      if (duration !== undefined && /^\d+ms$/.test(duration)) {
        delay = Number(duration.slice(0, -2));
        index++;
      }
    } else throw new Error('Invalid x-interact:model modifier.');
  }
  if ((seen.has('debounce') && timing !== 'live') || delay > 60000)
    throw new Error(
      'Debounce requires a live model and a delay from 0 to 60000 ms.',
    );
  return {
    path: expression,
    timing,
    delay: timing === 'live' ? delay : 250,
    explicitDebounce: seen.has('debounce'),
  };
}
