import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import { RequestActivity } from '../../../resources/ts/scheduling/request-activity';
import { RequestQueue } from '../../../resources/ts/scheduling/request-queue';
import { copyArguments } from '../../../resources/ts/scheduling/targets';
import type { Invocation, Json } from '../../../resources/ts/types';

const activities: RequestActivity[] = [];
function activity(): RequestActivity {
  const value = new RequestActivity(Alpine.reactive);
  activities.push(value);
  return value;
}
afterEach(() => {
  activities.splice(0).forEach((value) => value.dispose());
  vi.useRealTimers();
});

describe('targeted request activity', () => {
  it('matches action tuples structurally without confusing scalar values or synchronization', () => {
    const value = activity();
    const args: Json[] = [42, { enabled: false, title: null }];
    value.update([
      { action: 'remove', args },
      { action: null, args: [] },
    ]);
    expect(value.pending()).toBe(true);
    expect(value.pending('remove')).toBe(true);
    expect(value.pending('save')).toBe(false);
    expect(value.pending(null)).toBe(true);
    expect(value.pending('remove', 42)).toBe(false);
    expect(value.pending('remove', 42, { title: null, enabled: false })).toBe(
      true,
    );
    expect(value.pending('remove', '42', { title: null, enabled: false })).toBe(
      false,
    );
    expect(value.pending({ action: 'remove', args })).toBe(true);
    expect(value.pending({ action: 'remove', args: [] })).toBe(false);
    expect(value.loading({ action: null, delay: 0 })).toBe(true);
    value.update([]);
    expect(value.pending()).toBe(false);
    expect(value.loading('remove')).toBe(false);
  });
  it('shares delayed queries, starts at acceptance, and preserves continuous matching intervals', () => {
    vi.useFakeTimers();
    const value = activity();
    const first = { action: 'save', args: [] };
    const second = { action: 'save', args: [2] };
    value.update([first]);
    vi.advanceTimersByTime(100);
    expect(value.loading('save')).toBe(false);
    expect(value.loading({ action: 'save', delay: 200 })).toBe(false);
    expect(vi.getTimerCount()).toBe(1);
    value.update([first, second]);
    vi.advanceTimersByTime(99);
    expect(value.loading('save')).toBe(false);
    vi.advanceTimersByTime(1);
    expect(value.loading('save')).toBe(true);
    value.update([second]);
    expect(value.loading('save')).toBe(true);
    value.update([]);
    expect(value.loading('save')).toBe(false);
    value.update([first]);
    expect(value.loading('save')).toBe(false);
    value.dispose();
    expect(vi.getTimerCount()).toBe(0);
    vi.advanceTimersByTime(1000);
    expect(value.loading()).toBe(false);
  });
  it('supports independently delayed queries and late subscribers without waiting again', () => {
    vi.useFakeTimers();
    const value = activity();
    value.update([{ action: 'save', args: [] }]);
    expect(value.loading({ delay: 500 })).toBe(false);
    vi.advanceTimersByTime(300);
    expect(value.loading('save')).toBe(true);
    expect(value.loading({ delay: 500 })).toBe(false);
    vi.advanceTimersByTime(200);
    expect(value.loading({ delay: 500 })).toBe(true);
  });
  it('drives Alpine effects when both queue activity and the visual timer change', async () => {
    vi.useFakeTimers();
    const value = activity();
    const observed: boolean[] = [];
    const effect = Alpine.effect(() => observed.push(value.loading('save')));
    value.update([{ action: 'save', args: [] }]);
    await Promise.resolve();
    vi.advanceTimersByTime(200);
    await Promise.resolve();
    expect(observed.at(-1)).toBe(true);
    value.update([]);
    await Promise.resolve();
    expect(observed.at(-1)).toBe(false);
    Alpine.release(effect);
  });
  it('validates selectors and detaches invocation arguments', () => {
    const value = activity();
    for (const delay of [-1, 1.5, 60001, NaN])
      expect(() => value.loading({ delay })).toThrow('target');
    expect(() => value.pending(null, 1)).toThrow('target');
    expect(() => value.pending(undefined, 1)).toThrow('Arguments');
    expect(() => value.pending({ args: [1] })).toThrow('target');
    const source: Json[] = [{ title: 'original' }];
    const copy = copyArguments(source);
    source[0] = { title: 'changed' };
    expect(copy).toEqual([{ title: 'original' }]);
    expect(() => copyArguments([NaN])).toThrow('arguments');
  });
  it('publishes queued actions separately, coalesces syncs, and clears old work before recovery', async () => {
    const value = activity();
    let finish: (() => void) | undefined;
    const execute = vi
      .fn<(invocation: Invocation) => Promise<void>>()
      .mockImplementationOnce(
        () =>
          new Promise<void>((resolve) => {
            finish = resolve;
          }),
      )
      .mockResolvedValue(undefined);
    const queue = new RequestQueue(
      execute,
      () => {},
      (requests) => value.update(requests),
    );
    const first = queue.enqueue({ action: 'save', args: [] });
    const second = queue.enqueue({ action: 'remove', args: [42] });
    const sync = queue.enqueue({ action: null, args: [] });
    const sameSync = queue.enqueue({ action: null, args: [] });
    expect(value.pending('remove', 42)).toBe(true);
    expect(value.pending(null)).toBe(true);
    expect(execute).toHaveBeenCalledOnce();
    finish?.();
    await Promise.all([first, second, sync, sameSync]);
    expect(execute).toHaveBeenCalledTimes(3);
    expect(value.pending()).toBe(false);
    const failed = new RequestQueue(
      async () => {
        throw new Error('failure');
      },
      () => {
        expect(value.pending()).toBe(false);
      },
      (requests) => value.update(requests),
    );
    await expect(failed.enqueue({ action: 'save', args: [] })).rejects.toThrow(
      'failure',
    );
  });
  it('rejects work accepted after disposal without executing or publishing it', async () => {
    const execute = vi.fn<(invocation: Invocation) => Promise<void>>();
    const onFailure = vi.fn<(error: Error) => void>();
    const onChange = vi.fn<(requests: readonly Invocation[]) => void>();
    const queue = new RequestQueue(execute, onFailure, onChange);
    queue.dispose();
    expect(onChange).toHaveBeenCalledExactlyOnceWith([]);
    await expect(queue.enqueue({ action: 'save', args: [] })).rejects.toThrow(
      'Island was removed.',
    );
    expect(execute).not.toHaveBeenCalled();
    expect(onFailure).not.toHaveBeenCalled();
    expect(onChange).toHaveBeenCalledOnce();
  });
  it('normalizes unknown execution failures and clears queued work before recovery', async () => {
    const value = activity();
    const execute = vi.fn(async () => {
      throw 'transport failure';
    });
    const onFailure = vi.fn((error: Error) => {
      expect(error.message).toBe('Island request failed.');
      expect(value.pending()).toBe(false);
    });
    const queue = new RequestQueue(execute, onFailure, (requests) =>
      value.update(requests),
    );
    const first = queue.enqueue({ action: 'save', args: [] });
    const second = queue.enqueue({ action: 'remove', args: [42] });
    await expect(first).rejects.toThrow('Island request failed.');
    await expect(second).rejects.toThrow('Island request failed.');
    expect(execute).toHaveBeenCalledOnce();
    expect(onFailure).toHaveBeenCalledOnce();
  });
});
