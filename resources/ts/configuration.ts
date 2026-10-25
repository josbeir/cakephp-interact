import { record } from './state/values';
import type { RealtimeOptions } from './realtime/types';

/** Whole-millisecond scheduling and feedback defaults captured by one installation. */
export interface TimingDefaults {
  readonly debounce: number;
  readonly pollInterval: number;
  readonly loadingDelay: number;
}

/** Optional JavaScript overrides; explicit control and bridge settings take precedence. */
export interface InteractOptions {
  realtime?: RealtimeOptions;
  defaults?: Partial<TimingDefaults>;
}

export const defaultTiming: TimingDefaults = Object.freeze({
  debounce: 250,
  pollInterval: 5000,
  loadingDelay: 200,
});

/**
 * Normalize unknown configuration before any Alpine integrations are registered.
 * @throws Error When options contain unknown keys, invalid types, or out-of-range timings.
 */
export function timingDefaults(value: unknown = {}): TimingDefaults {
  if (
    !record(value) ||
    Object.keys(value).some((key) => key !== 'defaults' && key !== 'realtime')
  )
    throw new Error('Invalid Interact configuration.');
  const defaults = 'defaults' in value ? value.defaults : {};
  if (
    !record(defaults) ||
    Object.keys(defaults).some(
      (key) => !['debounce', 'pollInterval', 'loadingDelay'].includes(key),
    )
  )
    throw new Error('Invalid Interact browser defaults.');
  return Object.freeze({
    debounce: duration(
      'debounce' in defaults ? defaults.debounce : defaultTiming.debounce,
      0,
      60000,
    ),
    pollInterval: duration(
      'pollInterval' in defaults
        ? defaults.pollInterval
        : defaultTiming.pollInterval,
      1,
      2147483647,
    ),
    loadingDelay: duration(
      'loadingDelay' in defaults
        ? defaults.loadingDelay
        : defaultTiming.loadingDelay,
      0,
      60000,
    ),
  });
}

function duration(value: unknown, minimum: number, maximum: number): number {
  if (
    typeof value !== 'number' ||
    !Number.isInteger(value) ||
    value < minimum ||
    value > maximum
  )
    throw new Error('Invalid Interact timing in milliseconds.');
  return value;
}
