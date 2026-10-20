import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vite-plus/test';
import { pollBinding } from '../../resources/ts/bindings/poll';
import { Poller } from '../../resources/ts/scheduling/poller';

const pollers: Poller[] = [];
const observers: VisibilityObserver[] = [];

/**
 * Delivers controlled viewport transitions without depending on a layout engine.
 */
class VisibilityObserver implements IntersectionObserver {
  readonly root = null;
  readonly rootMargin = '0px';
  readonly scrollMargin = '0px';
  readonly thresholds = [0];
  readonly delay = 0;
  readonly trackVisibility = false;
  readonly observe = vi.fn();
  readonly unobserve = vi.fn();
  readonly disconnect = vi.fn();
  readonly takeRecords = (): IntersectionObserverEntry[] => [];

  /**
   * Retain the browser callback so tests can deliver intersection changes.
   */
  constructor(private readonly callback: IntersectionObserverCallback) {
    observers.push(this);
  }

  /**
   * Publish a single element's viewport state using complete native entry metadata.
   */
  emit(target: Element, visible: boolean): void {
    this.callback(
      [
        {
          target,
          isIntersecting: visible,
          intersectionRatio: visible ? 1 : 0,
          boundingClientRect: target.getBoundingClientRect(),
          intersectionRect: target.getBoundingClientRect(),
          rootBounds: null,
          time: performance.now(),
        },
      ],
      this,
    );
  }
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
  vi.stubGlobal('IntersectionObserver', VisibilityObserver);
});
afterEach(() => {
  pollers.splice(0).forEach((poller) => poller.dispose());
  observers.splice(0);
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

function mount(
  invoke = vi.fn<() => unknown>(),
  eligible = () => true,
  modifiers: string[] = [],
  failure = vi.fn<(cause: unknown) => void>(),
) {
  const element = document.createElement('section');
  const poller = new Poller(
    element,
    pollBinding(modifiers),
    eligible,
    invoke,
    failure,
  );
  pollers.push(poller);
  return { poller, element, invoke, failure };
}

describe('managed polling schedules', () => {
  it('parses one bounded whole duration and optional viewport restriction', () => {
    expect(pollBinding([])).toEqual({ interval: 5000, visible: false });
    expect(pollBinding(['visible', '2s'])).toEqual({
      interval: 2000,
      visible: true,
    });
    expect(pollBinding(['500ms', 'visible']).interval).toBe(500);
    expect(pollBinding(['1ms']).interval).toBe(1);
    expect(pollBinding(['2147483647ms']).interval).toBe(2147483647);
    for (const modifiers of [
      ['0s'],
      ['0ms'],
      ['-1s'],
      ['1.5s'],
      ['1m'],
      ['unknown'],
      ['visible', 'visible'],
      ['1s', '2s'],
      ['1s', '1s'],
      ['2147483648ms'],
      ['2147484s'],
      ['999999999999999999999999999999s'],
    ])
      expect(() => pollBinding(modifiers)).toThrow();
  });

  it('waits before the first tick and starts the next interval after completion', async () => {
    let finish = () => {};
    const pending = new Promise<void>((resolve) => {
      finish = resolve;
    });
    const invoke = vi.fn<() => unknown>().mockReturnValueOnce(pending);
    const { poller } = mount(invoke);
    await vi.advanceTimersByTimeAsync(4999);
    expect(invoke).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(invoke).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(20000);
    expect(invoke).toHaveBeenCalledOnce();
    finish();
    await vi.advanceTimersByTimeAsync(4999);
    expect(invoke).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(1);
    expect(invoke).toHaveBeenCalledTimes(2);
    poller.dispose();
    await vi.advanceTimersByTimeAsync(15000);
    expect(invoke).toHaveBeenCalledTimes(2);
  });

  it('skips blocked ticks without a backlog', async () => {
    let eligible = false;
    const { invoke } = mount(vi.fn(), () => eligible);
    await vi.advanceTimersByTimeAsync(30000);
    expect(invoke).not.toHaveBeenCalled();
    eligible = true;
    await vi.advanceTimersByTimeAsync(4999);
    expect(invoke).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(invoke).toHaveBeenCalledOnce();
  });

  it('suspends hidden tabs and resumes with a full interval', async () => {
    const { invoke } = mount();
    await vi.advanceTimersByTimeAsync(4000);
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(30000);
    expect(invoke).not.toHaveBeenCalled();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(4999);
    expect(invoke).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(invoke).toHaveBeenCalledOnce();
  });

  it('waits for the observed element to intersect and releases its observer', async () => {
    const { poller, element, invoke } = mount(vi.fn(), () => true, ['visible']);
    const observer = observers[0];
    if (!observer) throw new Error('Missing viewport observer.');
    expect(observer.observe).toHaveBeenCalledWith(element);
    await vi.advanceTimersByTimeAsync(10000);
    expect(invoke).not.toHaveBeenCalled();
    observer.emit(document.createElement('div'), true);
    await vi.advanceTimersByTimeAsync(10000);
    expect(invoke).not.toHaveBeenCalled();
    observer.emit(element, true);
    await vi.advanceTimersByTimeAsync(5000);
    expect(invoke).toHaveBeenCalledOnce();
    observer.emit(element, false);
    await vi.advanceTimersByTimeAsync(10000);
    expect(invoke).toHaveBeenCalledOnce();
    poller.dispose();
    observer.emit(element, true);
    await vi.advanceTimersByTimeAsync(10000);
    expect(invoke).toHaveBeenCalledOnce();
    expect(observer.disconnect).toHaveBeenCalledOnce();
  });

  it.each(['throw', 'reject'])(
    'reports an expression failure: %s',
    async (mode) => {
      const error = new Error('Expression failed.');
      const invoke = vi.fn<() => unknown>(() => {
        if (mode === 'throw') throw error;
        return Promise.reject(error);
      });
      const { failure } = mount(invoke);
      await vi.advanceTimersByTimeAsync(5000);
      expect(failure).toHaveBeenCalledExactlyOnceWith(error);
    },
  );

  it('does not restart after disposal when visibility changes during an in-flight expression', async () => {
    let finish = () => {};
    const pending = new Promise<void>((resolve) => {
      finish = resolve;
    });
    const { poller, invoke } = mount(vi.fn(() => pending));
    await vi.advanceTimersByTimeAsync(5000);
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(10000);
    expect(invoke).toHaveBeenCalledOnce();
    poller.dispose();
    finish();
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(10000);
    expect(invoke).toHaveBeenCalledOnce();
  });

  it('does not report a custom promise rejection after the declaration is disposed', async () => {
    let reject = (_cause: Error) => {};
    const pending = new Promise<void>((_resolve, fail) => {
      reject = fail;
    });
    const { poller, failure } = mount(vi.fn(() => pending));
    await vi.advanceTimersByTimeAsync(5000);
    poller.dispose();
    reject(new Error('Late expression failure.'));
    await vi.advanceTimersByTimeAsync(10000);
    expect(failure).not.toHaveBeenCalled();
  });

  it('lets an accepted expression complete while hidden without scheduling another request', async () => {
    let finish = () => {};
    const pending = new Promise<void>((resolve) => {
      finish = resolve;
    });
    const invoke = vi.fn<() => unknown>().mockReturnValueOnce(pending);
    mount(invoke);
    await vi.advanceTimersByTimeAsync(5000);
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    finish();
    await vi.advanceTimersByTimeAsync(10000);
    expect(invoke).toHaveBeenCalledOnce();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    await vi.advanceTimersByTimeAsync(5000);
    expect(invoke).toHaveBeenCalledTimes(2);
  });
});
