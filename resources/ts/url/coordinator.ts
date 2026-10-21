import { navigation } from '../navigation/registry';
import { clone, equal } from '../state/values';
import type { State } from '../types';
import type { UrlFields, UrlRestoration } from './types';
import { readQuery, writeQuery } from './codec';

interface Owner {
  fields: UrlFields;
  accepted: State | undefined;
  restore: (target: UrlRestoration) => void;
  failure: (cause: unknown) => void;
  root: HTMLElement | undefined;
}

/** Lifetime-scoped access to the page's shared URL; disposal releases parameter claims. */
export interface UrlRegistration {
  epoch(): number;
  accept(state: State, epoch: number, restoring: boolean): void;
  dispose(): void;
}

/**
 * Coordinates independent islands sharing one address bar and browser history.
 *
 * Navigation generations prevent older responses from undoing Back/Forward. History
 * stores URLs only; application history state is preserved and signed snapshots stay out of it.
 */
export class UrlCoordinator {
  protected readonly owners = new Set<Owner>();
  protected readonly claims = new Set<string>();
  protected generation = 0;
  protected initializing = false;
  protected suspended = false;
  protected readonly navigate = () => {
    if (!navigation(this.host)) this.restore();
  };

  /** Restore URL owners only after the page history controller has selected this document. */
  restore(): void {
    this.generation++;
    for (const owner of [...this.owners])
      this.safely(owner, () =>
        owner.restore({
          values: readQuery(this.host.location.search, owner.fields),
          epoch: this.generation,
        }),
      );
  }

  /** Invalidate old replies while a document visit is being prepared. */
  suspend(): void {
    this.suspended = true;
    this.generation++;
  }

  /** Reconcile retained owners against the committed destination before new islands initialize. */
  resume(restore: boolean): void {
    this.suspended = false;
    if (restore) this.restore();
  }

  constructor(
    protected readonly host: Window,
    protected readonly empty: () => void = () => {},
  ) {}

  /** Register all claims atomically and canonicalize initial values without pushing history. */
  register(
    fields: UrlFields,
    accepted: State | undefined,
    restore: Owner['restore'],
    failure: Owner['failure'] = (cause) => {
      throw cause;
    },
    root?: HTMLElement,
  ): UrlRegistration {
    for (const owner of this.owners)
      if (owner.root && !owner.root.isConnected) this.release(owner, false);
    for (const field of Object.values(fields))
      if (this.claims.has(field.name))
        throw new Error(
          `URL parameter "${field.name}" already belongs to another island. Use an alias or urlPrefix.`,
        );
    if (!this.owners.size)
      this.host.addEventListener('popstate', this.navigate);
    const owner: Owner = {
      fields,
      accepted: accepted === undefined ? undefined : clone(accepted),
      restore,
      failure,
      root,
    };
    this.owners.add(owner);
    for (const field of Object.values(fields)) this.claims.add(field.name);
    if (!this.initializing) {
      this.initializing = true;
      const epoch = this.generation;
      queueMicrotask(() => {
        this.initializing = false;
        if (epoch === this.generation && this.owners.size && !this.suspended) {
          try {
            this.initialize();
          } catch (cause: unknown) {
            for (const registered of this.owners) registered.failure(cause);
          }
        }
      });
    }
    return {
      epoch: () => this.generation,
      accept: (state, epoch, restoring) =>
        this.accept(owner, state, epoch, restoring),
      dispose: () => this.release(owner),
    };
  }

  /** Release claims immediately when a keyed root is replaced, before observer cleanup. */
  protected release(owner: Owner, finish = true): void {
    if (!this.owners.delete(owner)) return;
    for (const field of Object.values(owner.fields))
      this.claims.delete(field.name);
    if (!this.owners.size && finish) {
      this.host.removeEventListener('popstate', this.navigate);
      this.empty();
    }
  }

  /** Merge only this owner's accepted values into the current address bar. */
  protected accept(
    owner: Owner,
    state: State,
    epoch: number,
    restoring: boolean,
  ): void {
    const previous = owner.accepted;
    owner.accepted = clone(state);
    if (this.suspended || epoch !== this.generation || !this.owners.has(owner))
      return;
    const url = new URL(this.host.location.href);
    writeQuery(url, owner.fields, state);
    const push =
      !restoring &&
      previous !== undefined &&
      Object.entries(owner.fields).some(
        ([property, field]) =>
          field.history && !equal(previous[property], state[property]),
      );
    this.write(url, push);
  }

  /** Route native history and restoration failures through the owning island's feedback. */
  protected safely(owner: Owner, operation: () => void): void {
    try {
      operation();
    } catch (cause: unknown) {
      owner.failure(cause);
    }
  }

  protected initialize(): void {
    const url = new URL(this.host.location.href);
    for (const owner of this.owners)
      if (owner.accepted !== undefined)
        writeQuery(url, owner.fields, owner.accepted);
    this.write(url, false);
  }

  protected write(url: URL, push: boolean): void {
    if (url.href === this.host.location.href) return;
    const service = navigation(this.host);
    if (service) {
      service.query(url, push);
      return;
    }
    this.host.history[push ? 'pushState' : 'replaceState'](
      this.host.history.state,
      '',
      url,
    );
  }
}

const coordinators = new WeakMap<Window, UrlCoordinator>();

/** Acquire a page coordinator only when at least one island declares URL state. */
export function pageUrl(host: Window): UrlCoordinator {
  let coordinator = coordinators.get(host);
  if (!coordinator) {
    coordinator = new UrlCoordinator(host, () => {
      if (!navigation(host)) coordinators.delete(host);
    });
    coordinators.set(host, coordinator);
  }
  return coordinator;
}
