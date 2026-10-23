import type { Alpine } from 'alpinejs';
import type { IslandClient } from '../client';
import type { State } from '../types';
import { relation } from './protocol';
import { ChildBinding } from './child-binding';
import { clone } from '../state/values';
import { changedWritable } from '../state/drafts';
import { readMetadata } from '../transport/metadata';

/**
 * Owns the active parent/child connections for one Alpine installation.
 *
 * Connections follow actual direct DOM ownership and are released with their clients.
 * Signed input tickets are consumed only after a parent response has been accepted;
 * metadata never grants browser write access to props.
 */
export class Relationships {
  private readonly links = new Set<ChildBinding>();
  private readonly cleanups = new WeakMap<ChildBinding, () => void>();
  private clock = 0;

  constructor(
    private readonly Alpine: Alpine,
    private readonly resolve: (root: HTMLElement) => IslandClient | undefined,
  ) {}

  /** Attach lifecycle hooks before child directives initialize, deferring effects past parent morphs. */
  register(client: IslandClient): void {
    let cleanup: (() => void) | undefined;
    let disposed = false;
    let epoch = 0;
    let action: string | null = null;
    let sent: State = {};
    const flush = (values: State) => {
      for (const link of this.links)
        if (link.parent === client) link.flush(values);
    };
    client.composition = {
      flush,
      begin: (invocation, values) => {
        epoch = ++this.clock;
        action = invocation;
        sent = values;
        flush(sent);
      },
      preserve: (incoming) => {
        let values: State | undefined;
        for (const link of this.links) {
          if (link.parent !== client && link.child !== client) continue;
          values ??= clone(incoming);
          link.preserve(client, values, epoch);
        }
        return values
          ? changedWritable(
              values,
              incoming,
              readMetadata(client.root).writable,
            )
          : {};
      },
      capture: () => {
        for (const link of this.links)
          if (link.parent === client) link.capture();
      },
      updates: () => {
        let values: State = {};
        for (const link of this.links)
          if (link.child === client) values = link.updates();
        return values;
      },
      accepted: (replacement) => {
        for (const link of this.links)
          link.received(client, epoch, action, sent);
        for (const link of this.links)
          if (link.child === client) link.reconcile();
        let inputs: Map<IslandClient, string> | undefined;
        for (const link of this.links)
          if (link.parent === client) {
            if (replacement) inputs ??= this.inputs(client, replacement);
            link.accepted(inputs?.get(link.child));
          }
      },
      dispose: () => {
        disposed = true;
        cleanup?.();
        for (const link of this.links)
          if (link.parent === client || link.child === client) {
            this.cleanups.get(link)?.();
          }
      },
    };
    queueMicrotask(() => {
      if (disposed) return;
      try {
        cleanup = this.connect(client);
      } catch (cause: unknown) {
        client.compositionFailed(cause);
      }
    });
  }

  private connect(child: IslandClient): (() => void) | undefined {
    const raw = child.root.dataset.interactRelation;
    if (raw === undefined) return;
    const value: unknown = JSON.parse(raw);
    if (!relation(value)) throw new Error('Invalid child relationship.');
    const root =
      child.root.parentElement?.closest<HTMLElement>('[data-interact-id]');
    const parent = root ? this.resolve(root) : undefined;
    if (
      !parent ||
      parent.root.dataset.interactId !== value.parentId ||
      parent.root.dataset.interactEndpoint !==
        child.root.dataset.interactEndpoint
    )
      throw new Error('Invalid direct parent.');
    const link = new ChildBinding(parent, child, value);
    this.links.add(link);
    const runner = this.Alpine.effect(() => {
      try {
        link.reconcile();
      } catch (cause: unknown) {
        child.compositionFailed(cause);
      }
    });
    const cleanup = () => {
      this.Alpine.release(runner);
      link.dispose();
      this.links.delete(link);
      this.cleanups.delete(link);
    };
    this.cleanups.set(link, cleanup);
    return cleanup;
  }

  private inputs(
    parent: IslandClient,
    replacement: HTMLElement,
  ): Map<IslandClient, string> {
    const inputs = new Map<IslandClient, string>();
    const markers = replacement.querySelectorAll<HTMLElement>(
      '[data-interact-parent-inputs]',
    );
    if (!markers.length) return inputs;
    const children = new Map<string, HTMLElement>();
    for (const root of parent.root.querySelectorAll<HTMLElement>(
      '[data-interact-id]',
    )) {
      const id = root.dataset.interactId;
      // A later duplicate must not bypass the first match's ownership check.
      if (id !== undefined && !children.has(id)) children.set(id, root);
    }
    for (const marker of markers) {
      if (marker.parentElement?.closest('[data-interact-id]') !== replacement)
        continue;
      const id = marker.dataset.interactPreserve;
      const ticket = marker.dataset.interactParentInputs;
      const root = id === undefined ? undefined : children.get(id);
      const child = root ? this.resolve(root) : undefined;
      if (
        child &&
        ticket &&
        child.root.parentElement?.closest('[data-interact-id]') === parent.root
      )
        inputs.set(child, ticket);
    }
    return inputs;
  }
}
