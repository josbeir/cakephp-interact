/**
 * The interval and optional viewport restriction for one island's polling declaration.
 */
export type PollBinding = {
  interval: number;
  visible: boolean;
};

/**
 * Parse whole milliseconds or seconds without allowing overflowing browser timers.
 */
export function pollBinding(modifiers: string[]): PollBinding {
  let interval = 5000;
  let durationSeen = false;
  let visible = false;
  for (const modifier of modifiers) {
    if (modifier === 'visible' && !visible) {
      visible = true;
      continue;
    }
    if (durationSeen || !/^\d+(?:ms|s)$/.test(modifier))
      throw new Error(
        'Use one polling duration and an optional .visible modifier.',
      );
    const milliseconds = modifier.endsWith('ms');
    interval =
      Number(modifier.slice(0, milliseconds ? -2 : -1)) *
      (milliseconds ? 1 : 1000);
    if (
      !Number.isSafeInteger(interval) ||
      interval < 1 ||
      interval > 2147483647
    )
      throw new Error(
        'Polling intervals must be from 1 to 2147483647 milliseconds.',
      );
    durationSeen = true;
  }
  return { interval, visible };
}
