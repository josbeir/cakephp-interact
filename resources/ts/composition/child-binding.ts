import type { IslandClient } from '../client';
import { read, write } from '../state/paths';
import { clone, equal } from '../state/values';
import type { Json, State } from '../types';
import type { Relation } from './types';

/**
 * Mirrors explicitly bound values between a child and its direct parent's draft.
 *
 * Accepted responses use each client's existing draft reconciliation. Local copies
 * remain independent JSON values; source tracking prevents propagation from becoming
 * another input event, and timing belongs to the parent binding.
 */
export class ChildBinding {
  private readonly previous = new Map<string, { parent: Json; child: Json }>();
  private readonly timers = new Map<string, ReturnType<typeof setTimeout>>();
  private ready = false;
  private stopped = false;
  private reconciling = false;
  private parentEpoch = 0;
  private childEpoch = 0;
  private readonly lifetime = new AbortController();

  constructor(
    readonly parent: IslandClient,
    readonly child: IslandClient,
    readonly relation: Relation,
  ) {
    child.root.addEventListener(
      'focusout',
      (event) => {
        if (
          event.relatedTarget instanceof Node &&
          child.root.contains(event.relatedTarget)
        )
          return;
        if (
          Object.values(relation.models).some(
            (binding) => binding.timing === 'blur',
          )
        )
          this.synchronize();
      },
      { signal: this.lifetime.signal },
    );
  }

  /** Observe only declared model values; unrelated child state never travels upward. */
  reconcile(): void {
    if (this.stopped || this.reconciling) return;
    this.reconciling = true;
    let initializeDraft = false;
    try {
      if (!this.child.isMounted()) {
        for (const [name, binding] of Object.entries(this.relation.models)) {
          const value = read(this.parent.bridge.state, binding.path);
          if (value === undefined)
            throw new Error('Missing parent model value.');
          if (!this.previous.has(name)) this.remember(name, value, value);
        }
        return;
      }
      for (const [name, binding] of Object.entries(this.relation.models)) {
        if (!this.parent.canModel(binding.path) || !this.child.canModel(name))
          throw new Error('Model bindings require writable declarations.');
        const parent = read(this.parent.bridge.state, binding.path);
        const child = this.child.bridge.state[name];
        if (parent === undefined || child === undefined)
          throw new Error('Missing bound model value.');
        const previous = this.previous.get(name);
        const parentChanged =
          previous !== undefined && !equal(parent, previous.parent);
        if (
          parentChanged ||
          (!previous && !equal(parent, this.parent.modelBaseline(binding.path)))
        ) {
          if (!this.ready) initializeDraft = true;
          this.remember(name, parent, parent);
          write(this.child.bridge.state, name, this.copy(parent));
          this.child.refreshModels();
        } else if (!previous || !equal(child, previous.child)) {
          this.remember(name, child, child);
          write(this.parent.bridge.state, binding.path, this.copy(child));
          this.parent.refreshModels();
          if (this.ready && !equal(parent, child)) this.schedule(name);
        }
      }
      this.ready = true;
      if (initializeDraft && !this.child.bridge.paused)
        void this.child.bridge.sync();
    } finally {
      this.reconciling = false;
    }
  }

  /** Flush unsent child control values before the parent's action captures its draft. */
  capture(): void {
    if (this.stopped || !this.child.isMounted()) return;
    this.child.captureModels();
    this.reconcile();
  }

  /** A parent acceptance refreshes changed PHP input views without remounting the child. */
  accepted(): void {
    this.reconcile();
    if (!this.child.isMounted() || this.child.bridge.paused) return;
    if (
      Object.keys(this.relation.models).some(
        (name) =>
          !equal(this.child.bridge.state[name], this.child.modelBaseline(name)),
      )
    ) {
      void this.child.bridge.sync();
    }
  }

  /** Preserve a later accepted reset even when its value equals an older request's original draft. */
  preserve(client: IslandClient, incoming: State, epoch: number): void {
    for (const [name, binding] of Object.entries(this.relation.models)) {
      if (client === this.child && epoch < this.parentEpoch) {
        const value = read(this.parent.bridge.state, binding.path);
        if (value !== undefined) write(incoming, name, this.copy(value));
      }
      if (client === this.parent && epoch < this.childEpoch) {
        const value = this.child.bridge.state[name];
        if (value !== undefined)
          write(incoming, binding.path, this.copy(value));
      }
    }
  }

  /** Record meaningful accepted writes; an unrelated background refresh does not supersede an action. */
  received(
    client: IslandClient,
    epoch: number,
    action: string | null,
    sent: State,
  ): void {
    if (client === this.parent) {
      const changed = Object.values(this.relation.models).some(
        (binding) =>
          !equal(
            read(sent, binding.path),
            read(client.bridge.state, binding.path),
          ),
      );
      if (action !== null || changed)
        this.parentEpoch = Math.max(this.parentEpoch, epoch);
    }
    if (client === this.child) {
      const changed = Object.keys(this.relation.models).some(
        (name) => !equal(sent[name], client.bridge.state[name]),
      );
      if (action !== null || changed)
        this.childEpoch = Math.max(this.childEpoch, epoch);
      this.reconcile();
      if (epoch < this.parentEpoch) this.accepted();
    }
  }

  /** Include every bound value when an older parent ticket accompanies a newer local draft. */
  updates(): State {
    const values: State = {};
    for (const name of Object.keys(this.relation.models)) {
      const value = this.child.bridge.state[name];
      if (value !== undefined) values[name] = this.copy(value);
    }
    return values;
  }

  /** Release focus listeners and unsent synchronization when either island disappears. */
  dispose(): void {
    this.stopped = true;
    this.lifetime.abort();
    for (const timer of this.timers.values()) clearTimeout(timer);
    this.timers.clear();
  }

  private schedule(name: string): void {
    const binding = this.relation.models[name];
    if (!binding || this.parent.bridge.paused) return;
    if (binding.timing === 'change') this.synchronize();
    if (binding.timing === 'live') {
      clearTimeout(this.timers.get(name));
      this.timers.set(
        name,
        setTimeout(() => {
          this.timers.delete(name);
          this.synchronize();
        }, binding.delay),
      );
    }
  }

  private synchronize(): void {
    if (!this.stopped && !this.parent.bridge.paused)
      void this.parent.bridge.sync();
  }

  private remember(name: string, parent: Json, child: Json): void {
    this.previous.set(name, {
      parent: this.copy(parent),
      child: this.copy(child),
    });
  }

  private copy(value: Json): Json {
    return clone({ value }).value ?? null;
  }
}
