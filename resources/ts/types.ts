import type { Redirect } from './effects/browser';
import type { UrlFields, UrlRestoration } from './url/types';
import type { UploadField, UploadJob, UploadMetadata } from './uploads/types';
/**
 * Values accepted by the JSON transport, including nested lists and records.
 */
export type Json =
  | null
  | boolean
  | number
  | string
  | Json[]
  | { [key: string]: Json };

/**
 * Browser-visible props and state, indexed by their declared PHP property names.
 */
export type State = Record<string, Json>;

/**
 * Native PHP type metadata for properties that explicitly accept browser edits.
 */
export type Writable = Record<string, { type: string; nullable: boolean }>;

/**
 * An explicit action call, or synchronization when the action name is null.
 */
export interface Invocation {
  action: string | null;
  args: Json[];
  upload?: UploadJob;
  uploadRevision?: number;
  urlEpoch?: number;
  navigationEpoch?: number;
  urlRestore?: UrlRestoration;
}

/**
 * Select accepted requests by action and, optionally, a complete argument tuple.
 *
 * Omitted actions match all requests; null selects synchronization only.
 */
export interface RequestTarget {
  action?: string | null;
  args?: Json[];
}

/**
 * Configure visual feedback without delaying execution or submission protection.
 */
export interface LoadingTarget extends RequestTarget {
  delay?: number;
}

/** Terminal navigation accepted for an explicitly invoked island action. */
export interface RedirectResponse {
  version: 1;
  id: string;
  redirect: Redirect;
}

export type UpdateResponse =
  | IslandResponse
  | RenderlessResponse
  | RedirectResponse;

/**
 * Accepted server state, including validation feedback and optional download effects.
 * Browser effects never establish authorization or durable business messages.
 */
export interface StateResponse {
  download?: string;
  uploads?: UploadMetadata;
  url?: UrlFields;
  version: 1;
  id: string;
  snapshot: string;
  state: State;
  writable: Writable;
  errors: Record<string, string[]>;
  events: { name: string; detail: State }[];
}

/**
 * A complete rendered island root.
 */
export interface IslandResponse extends StateResponse {
  html: string;
}

/**
 * An accepted action that retains the existing DOM.
 */
export interface RenderlessResponse extends StateResponse {
  html: null;
}

/**
 * Stable failure categories shared by application markup and request error handlers.
 */
export type FailureCode =
  | 'snapshot_expired'
  | 'session_changed'
  | 'invalid_snapshot'
  | 'unauthenticated'
  | 'csrf_failed'
  | 'forbidden'
  | 'invalid_request'
  | 'upload_too_large'
  | 'server_error'
  | 'http_error'
  | 'network_error'
  | 'unexpected_redirect'
  | 'invalid_response'
  | 'client_error'
  | 'cancelled';

/**
 * Safe request feedback; retry means a fresh explicit attempt, never automatic replay.
 */
export interface RequestFailure {
  readonly code: FailureCode;
  readonly message: string;
  readonly status: number | null;
  readonly recovery: 'retry' | 'reload' | 'authenticate' | 'none';
}

/**
 * A settled request outcome; expected failures are also published through state and events.
 *
 * A redirect result confirms navigation was requested. A renderable 422 response is accepted successfully and supplies field errors separately.
 */
export type RequestResult =
  | { readonly ok: true }
  | { readonly ok: false; readonly error: RequestFailure };

/**
 * Reactive application API exposed through Alpine's `$server` magic.
 *
 * Editing state creates a draft. Calling sync() or call() sends it through the
 * island's queue; synchronization alone never implies application persistence.
 */
export interface IslandBridge {
  uploads: Record<string, UploadField>;
  state: State;
  busy: boolean;

  /**
   * Match queued and executing requests immediately, optionally by exact arguments.
   */
  pending(selector?: string | null | RequestTarget, ...args: Json[]): boolean;

  /**
   * Match continuously pending work after 200 ms, or the target's delay override.
   */
  loading(selector?: string | null | LoadingTarget, ...args: Json[]): boolean;

  /**
   * Application validation feedback returned by the last accepted render.
   */
  errors: Record<string, string[]>;

  /**
   * A transport or protocol failure, separate from application validation.
   */
  error: RequestFailure | null;
  readonly paused: boolean;

  /**
   * Export all current writable values, including synchronized but unsaved drafts.
   */
  exportDraft(): string;

  readonly dirty: boolean;

  /**
   * Synchronize current writable drafts, coalescing with pending synchronization.
   */
  sync(): Promise<RequestResult>;

  /**
   * Queue an explicit action after earlier calls, using validated JSON arguments.
   */
  call(action: string, ...args: Json[]): Promise<RequestResult>;
}

/**
 * Replaceable HTTP boundary used by the runtime and controlled transport tests.
 */
export type Fetcher = (url: string, init: RequestInit) => Promise<Response>;

/**
 * Morph markup while retaining the owned root, local Alpine state, and child islands.
 */
export type Patcher = (root: HTMLElement, replacement: HTMLElement) => void;
