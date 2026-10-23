import { RequestActivity } from '../scheduling/request-activity';
import type { Invocation } from '../types';

export interface NavigationStore {
  readonly pending: boolean;
  loading(options?: { delay?: number }): boolean;
}

/**
 * Reuses island feedback timing for page visits without transporting presentation settings.
 * Applications own indicator markup; equivalent Alpine loading queries share timers.
 */
export class NavigationActivity {
  readonly store: NavigationStore;
  protected readonly requests: RequestActivity;

  constructor(reactive: <T extends object>(value: T) => T, loadingDelay = 200) {
    this.requests = new RequestActivity(reactive, loadingDelay);
    const requests = this.requests;
    this.store = {
      get pending() {
        return requests.pending();
      },
      loading: (options) => requests.loading(options),
    };
  }

  start(): void {
    const visit: Invocation = { action: null, args: [] };
    this.requests.update([visit]);
  }

  finish(): void {
    this.requests.dispose();
  }
}
