import type { State } from '../types';

/** Request lifecycle hooks owned by the installation's parent/child coordinator. */
export interface CompositionHooks {
  begin(action: string | null, sent: State): void;
  preserve(incoming: State): State;
  capture(): void;
  flush(sent: State): void;
  accepted(replacement?: HTMLElement): void;
  updates(): State;
  dispose(): void;
}

export interface ModelOptions {
  path: string;
  timing: 'defer' | 'live' | 'change' | 'blur';
  delay?: number;
}

export interface Relation {
  parentId: string;
  models: Record<string, ModelOptions>;
}
