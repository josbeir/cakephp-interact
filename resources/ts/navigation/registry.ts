import type { Redirect } from '../effects/browser';

/** Page-owned navigation boundary shared with island outcomes and URL bindings. */
export interface NavigationService {
  epoch(): number;
  visit(url: string, options?: VisitOptions): Promise<boolean>;
  invalidate(): void;
  query(url: URL, push: boolean): void;
}

export interface VisitOptions {
  replace?: boolean;
  preserveScroll?: boolean;
  history?: boolean;
}

const services = new WeakMap<Window, NavigationService>();

/** Install one document owner without coupling island clients to Alpine's page lifecycle. */
export function registerNavigation(
  host: Window,
  service: NavigationService,
): () => void {
  services.set(host, service);
  return () => {
    if (services.get(host) === service) services.delete(host);
  };
}

export function navigation(host: Window): NavigationService | undefined {
  return services.get(host);
}

/** Enhanced outcomes share the controller; unsupported installations retain native redirects. */
export async function enhancedRedirect(
  target: Redirect,
  native: () => void,
): Promise<boolean> {
  const service = navigation(window);
  if (service) return service.visit(target.url, { replace: target.replace });
  native();
  return true;
}
