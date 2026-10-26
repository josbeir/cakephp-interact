import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import {
  NavigationHistory,
  pageEntry,
} from '../../../resources/ts/navigation/history';

let history: NavigationHistory;
beforeEach(() => {
  vi.useFakeTimers();
  window.history.replaceState({ app: { draft: 'keep' } }, '', '/scroll');
  history = new NavigationHistory(window, vi.fn());
});
afterEach(() => {
  history.dispose();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

it('bounds sustained scroll writes and saves the latest position in each interval', () => {
  const replace = vi.spyOn(window.history, 'replaceState');
  const y = vi.spyOn(window, 'scrollY', 'get');
  for (let index = 1; index <= 120; index++) {
    y.mockReturnValue(index);
    window.dispatchEvent(new Event('scroll'));
    vi.advanceTimersByTime(10);
  }
  expect(replace.mock.calls.length).toBeLessThanOrEqual(2);
  expect(replace.mock.calls.length).toBeGreaterThan(0);
  vi.advanceTimersByTime(500);
  expect(pageEntry(window.history.state)?.y).toBe(120);
  expect(window.history.state).toMatchObject({ app: { draft: 'keep' } });
});

it('flushes pending scroll immediately before navigation and cancels its delayed write', () => {
  const y = vi.spyOn(window, 'scrollY', 'get').mockReturnValue(250);
  const replace = vi.spyOn(window.history, 'replaceState');
  window.dispatchEvent(new Event('scroll'));
  history.save();
  expect(pageEntry(window.history.state)?.y).toBe(250);
  expect(replace).toHaveBeenCalledOnce();
  y.mockReturnValue(0);
  history.commit(new URL('/next', window.location.href), false);
  vi.runAllTimers();
  expect(replace).toHaveBeenCalledOnce();
  expect(pageEntry(window.history.state)?.y).toBe(0);
});

it('does not write a queued scroll into an unrelated history entry', () => {
  window.dispatchEvent(new Event('scroll'));
  window.history.replaceState({ foreign: true }, '', '/foreign');
  const replace = vi.spyOn(window.history, 'replaceState');
  vi.runAllTimers();
  expect(replace).not.toHaveBeenCalled();
  expect(window.history.state).toEqual({ foreign: true });
});

it.each(['popstate', 'dispose', 'query'])(
  'clears queued scroll work on %s',
  (transition) => {
    window.dispatchEvent(new Event('scroll'));
    if (transition === 'popstate')
      window.dispatchEvent(new PopStateEvent('popstate', { state: null }));
    if (transition === 'dispose') history.dispose();
    if (transition === 'query')
      history.query(new URL('/scroll?q=one', window.location.href), true);
    const replace = vi.spyOn(window.history, 'replaceState');
    vi.runAllTimers();
    expect(replace).not.toHaveBeenCalled();
    expect(vi.getTimerCount()).toBe(0);
  },
);
