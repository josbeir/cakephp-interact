import type { RequestFailure } from '../types';

/** The operation represented by one accepted queue task. */
export type RequestKind =
  | 'action'
  | 'sync'
  | 'initialize'
  | 'upload-stage'
  | 'upload-remove'
  | 'restore';

/** Completion describes browser handling, never an exactly-once server guarantee. */
export type RequestOutcome =
  | 'success'
  | 'validation'
  | 'redirect'
  | 'failed'
  | 'cancelled'
  | 'ignored';

/** Monotonic durations in milliseconds; unentered execution phases are null. */
export interface RequestTimings {
  readonly queueMs: number;
  readonly prepareMs: number | null;
  readonly responseMs: number | null;
  readonly applyMs: number | null;
  readonly totalMs: number;
}

interface RequestIdentity {
  readonly requestId: number;
  readonly islandId: string;
  readonly kind: RequestKind;
  readonly action: string | null;
  readonly elapsedMs: number;
  readonly transport: 'fetch' | 'xhr' | null;
  readonly httpStatus: number | null;
}

/** Detached, read-only observations with no request bodies or original exceptions. */
export type RequestLifecycleDetail = RequestIdentity &
  (
    | { readonly phase: 'queued' }
    | { readonly phase: 'started' }
    | { readonly phase: 'response' }
    | {
        readonly phase: 'applied';
        readonly rendered: boolean;
        readonly outcome: 'success' | 'validation';
      }
    | {
        readonly phase: 'finished';
        readonly outcome: RequestOutcome;
        readonly stage: 'queue' | 'prepare' | 'response' | 'apply';
        readonly failure: RequestFailure | null;
        readonly timings: RequestTimings;
      }
  );

type RequestEvents = {
  [
    Detail in RequestLifecycleDetail as `interact:request-${Detail['phase']}`
  ]: CustomEvent<Detail>;
};

declare global {
  interface HTMLElementEventMap {
    'interact:request-queued': RequestEvents['interact:request-queued'];
    'interact:request-started': RequestEvents['interact:request-started'];
    'interact:request-response': RequestEvents['interact:request-response'];
    'interact:request-applied': RequestEvents['interact:request-applied'];
    'interact:request-finished': RequestEvents['interact:request-finished'];
  }
  interface DocumentEventMap {
    'interact:request-queued': RequestEvents['interact:request-queued'];
    'interact:request-started': RequestEvents['interact:request-started'];
    'interact:request-response': RequestEvents['interact:request-response'];
    'interact:request-applied': RequestEvents['interact:request-applied'];
    'interact:request-finished': RequestEvents['interact:request-finished'];
  }
  interface WindowEventMap {
    'interact:request-queued': RequestEvents['interact:request-queued'];
    'interact:request-started': RequestEvents['interact:request-started'];
    'interact:request-response': RequestEvents['interact:request-response'];
    'interact:request-applied': RequestEvents['interact:request-applied'];
    'interact:request-finished': RequestEvents['interact:request-finished'];
  }
}
