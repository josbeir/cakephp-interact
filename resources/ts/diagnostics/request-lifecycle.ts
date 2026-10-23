import type { RequestFailure } from '../types';
import { IslandRequestError, requestError } from '../transport/failure';
import type {
  RequestKind,
  RequestLifecycleDetail,
  RequestOutcome,
  RequestTimings,
} from './types';

const sequences = new WeakMap<Document, number>();

/**
 * Tracks one queue task without retaining payloads, histories, or raw exceptions.
 *
 * Queue transitions own its lifetime; the client records transport and application
 * boundaries. Reentrant notifications stay ordered even if a listener removes an island.
 */
export class RequestLifecycle {
  private readonly requestId: number;
  private readonly action: string | null;
  private readonly queuedAt = performance.now();
  private startedAt: number | undefined;
  private sentAt: number | undefined;
  private applyingAt: number | undefined;
  private transport: 'fetch' | 'xhr' | null = null;
  private httpStatus: number | null = null;
  private stage: 'queue' | 'prepare' | 'response' | 'apply' = 'queue';
  private outcome: RequestOutcome | undefined;
  private validation = false;
  private failure: RequestFailure | null = null;
  private announced = false;
  private finished = false;
  private publishing = false;
  private readonly notifications: RequestLifecycleDetail[] = [];

  constructor(
    private readonly root: HTMLElement,
    private readonly islandId: string,
    private readonly kind: RequestKind,
    action: unknown,
  ) {
    this.action = typeof action === 'string' ? action : null;
    this.requestId = (sequences.get(root.ownerDocument) ?? 0) + 1;
    sequences.set(root.ownerDocument, this.requestId);
  }

  /** Announce acceptance once, including tasks cancelled by a reentrant listener. */
  queued(): void {
    if (this.announced) return;
    this.announced = true;
    this.emit({ ...this.identity(), phase: 'queued' });
  }

  /** Begin execution; preparation may still prevent a transport attempt. */
  started(): void {
    this.startedAt = performance.now();
    this.stage = 'prepare';
    this.emit({ ...this.identity(), phase: 'started' });
  }

  /** Mark the actual browser transport invocation, without exposing its parameters. */
  sending(transport: 'fetch' | 'xhr'): void {
    this.sentAt = performance.now();
    this.transport = transport;
    this.stage = 'response';
  }

  /** Record the transport result before Interact reads and validates its envelope. */
  response(status: number): void {
    this.httpStatus = status || null;
    this.emit({ ...this.identity(), phase: 'response' });
  }

  /** Retain logical validation status, including SSE terminal HTTP-422 equivalents. */
  validated(status: number): void {
    this.validation = status === 422;
    this.applyingAt = performance.now();
    this.stage = 'apply';
  }

  /** Publish completed state, DOM, and effect handling without passing live state. */
  applied(rendered: boolean): void {
    this.outcome = this.validation ? 'validation' : 'success';
    this.emit({
      ...this.identity(),
      phase: 'applied',
      rendered,
      outcome: this.outcome,
    });
  }

  /** Record terminal handling that does not apply an island payload. */
  result(outcome: 'redirect' | 'ignored' | 'cancelled'): void {
    this.outcome = outcome;
  }

  /** Snapshot safe feedback before application error listeners can mutate bridge data. */
  failed(error: unknown): void {
    const failure =
      error instanceof IslandRequestError
        ? error.failure
        : requestError('client_error', null).failure;
    this.outcome = failure.code === 'cancelled' ? 'cancelled' : 'failed';
    this.failure =
      this.outcome === 'failed'
        ? Object.freeze({
            code: failure.code,
            message: failure.message,
            status: failure.status,
            recovery: failure.recovery,
          })
        : null;
  }

  /** Complete once after queue cleanup, including unsent discarded work. */
  finish(cancelled = false): void {
    if (this.finished) return;
    this.finished = true;
    this.queued();
    const now = performance.now();
    const outcome = this.outcome ?? (cancelled ? 'cancelled' : 'success');
    const timings: RequestTimings = Object.freeze({
      queueMs: (this.startedAt ?? now) - this.queuedAt,
      prepareMs:
        this.startedAt === undefined
          ? null
          : (this.sentAt ?? now) - this.startedAt,
      responseMs:
        this.sentAt === undefined
          ? null
          : (this.applyingAt ?? now) - this.sentAt,
      applyMs: this.applyingAt === undefined ? null : now - this.applyingAt,
      totalMs: now - this.queuedAt,
    });
    this.emit({
      ...this.identity(now),
      phase: 'finished',
      outcome,
      stage: this.stage,
      failure: this.failure,
      timings,
    });
  }

  private identity(now = performance.now()) {
    return {
      requestId: this.requestId,
      islandId: this.islandId,
      kind: this.kind,
      action: this.action,
      elapsedMs: now - this.queuedAt,
      transport: this.transport,
      httpStatus: this.httpStatus,
    };
  }

  private emit(detail: RequestLifecycleDetail): void {
    this.notifications.push(Object.freeze(detail));
    if (this.publishing) return;
    this.publishing = true;
    try {
      for (
        let next = this.notifications.shift();
        next;
        next = this.notifications.shift()
      ) {
        const target = this.root.isConnected
          ? this.root
          : this.root.ownerDocument;
        target.dispatchEvent(
          new CustomEvent(`interact:request-${next.phase}`, {
            detail: next,
            bubbles: true,
            composed: true,
          }),
        );
      }
    } finally {
      this.publishing = false;
    }
  }
}
