/**
 * Values accepted by the JSON transport, including nested lists and records.
 */
export type Json =
  null | boolean | number | string | Json[] | { [key: string]: Json };

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
}

/**
 * Verified server render accepted from the versioned update endpoint.
 *
 * Validation feedback uses the same envelope as a successful render. Events are
 * browser effects rather than trusted authorization or durable business messages.
 */
export interface IslandResponse {
  version: 1;
  id: string;
  html: string;
  snapshot: string;
  state: State;
  writable: Writable;
  errors: Record<string, string[]>;
  events: { name: string; detail: State }[];
}

/**
 * Reactive application API exposed through Alpine's `$server` magic.
 *
 * Editing state creates a draft. Calling sync() or call() sends it through the
 * island's queue; synchronization alone never implies application persistence.
 */
export interface IslandBridge {
  state: State;
  busy: boolean;
  /**
   * Application validation feedback returned by the last accepted render.
   */
  errors: Record<string, string[]>;
  /**
   * A transport or protocol failure, separate from application validation.
   */
  error: string | null;
  readonly dirty: boolean;
  /**
   * Synchronize current writable drafts, coalescing with pending synchronization.
   */
  sync(): Promise<void>;
  /**
   * Queue an explicit action after earlier calls, using validated JSON arguments.
   */
  call(action: string, ...args: Json[]): Promise<void>;
}

/**
 * Replaceable HTTP boundary used by the runtime and controlled transport tests.
 */
export type Fetcher = (url: string, init: RequestInit) => Promise<Response>;

/**
 * Morph markup while retaining the owned root, local Alpine state, and child islands.
 */
export type Patcher = (root: HTMLElement, replacement: HTMLElement) => void;
