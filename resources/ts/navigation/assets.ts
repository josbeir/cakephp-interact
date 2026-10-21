import { destination } from '../effects/browser';

function executable(script: HTMLScriptElement): boolean {
  const type = script.type.trim().toLowerCase();
  return (
    !type ||
    ['module', 'importmap', 'speculationrules'].includes(type) ||
    /^(?:text|application)\/(?:x-)?(?:java|ecma)script(?:1\.[0-5])?$/.test(
      type,
    ) ||
    ['text/jscript', 'text/livescript'].includes(type)
  );
}

function key(script: HTMLScriptElement, base: string): string {
  return JSON.stringify([
    new URL(script.getAttribute('src') ?? '', base).href,
    script.type,
    script.integrity,
    script.crossOrigin,
    script.noModule,
  ]);
}

/**
 * Loads new external assets once under the original document's security policy.
 * Scripts register integrations before incoming Alpine initialization; page work uses lifecycle events.
 */
export class PageAssets {
  protected readonly scripts = new Set<string>();

  constructor(protected readonly document: Document) {
    for (const script of document.querySelectorAll<HTMLScriptElement>(
      'script[src]',
    ))
      this.scripts.add(key(script, document.baseURI));
  }

  async prepare(
    next: Document,
    url: string,
    signal: AbortSignal,
  ): Promise<() => void> {
    const scripts = [
      ...next.querySelectorAll<HTMLScriptElement>('script'),
    ].filter(executable);
    if (
      scripts.some(
        (script) => !script.hasAttribute('src') && script.textContent?.trim(),
      )
    )
      throw new Error('Executable inline scripts require native navigation.');
    const staged: { link: HTMLLinkElement; media: string }[] = [];
    const discard = () => {
      for (const { link } of staged) link.remove();
    };
    signal.addEventListener('abort', discard, { once: true });
    try {
      for (const link of next.head.querySelectorAll<HTMLLinkElement>(
        'link[rel="stylesheet"]',
      )) {
        const href = new URL(link.getAttribute('href') ?? '', url).href;
        link.href = href;
        const existing = [
          ...this.document.head.querySelectorAll<HTMLLinkElement>(
            'link[rel="stylesheet"]',
          ),
        ].some(
          (node) =>
            node.href === href &&
            node.integrity === link.integrity &&
            node.media === link.media,
        );
        if (existing) continue;
        const clone = link.cloneNode(true);
        if (!(clone instanceof HTMLLinkElement) || !destination(href))
          throw new Error('Invalid stylesheet.');
        const media = link.media;
        clone.media = 'not all';
        staged.push({ link: clone, media });
        await this.load(clone, signal);
      }
      for (const script of scripts) {
        if (!script.hasAttribute('src') || script.noModule) continue;
        const identity = key(script, url);
        if (this.scripts.has(identity)) continue;
        const clone = this.document.createElement('script');
        for (const attribute of script.attributes)
          clone.setAttribute(attribute.name, attribute.value);
        clone.src = new URL(script.getAttribute('src') ?? '', url).href;
        if (!destination(clone.src))
          throw new Error('Invalid script destination.');
        clone.async = false;
        await this.load(clone, signal);
        this.scripts.add(identity);
      }
      signal.throwIfAborted();
      return () => {
        signal.removeEventListener('abort', discard);
        for (const { link, media } of staged) link.media = media;
      };
    } catch (cause: unknown) {
      signal.removeEventListener('abort', discard);
      discard();
      throw cause;
    }
  }

  /** Synchronize metadata and inline styles; loaded external assets retain document lifetime. */
  head(next: Document): void {
    const keep = (node: Element) =>
      node.matches('script[src], link[rel="stylesheet"]');
    for (const node of [...this.document.head.children])
      if (!keep(node)) node.remove();
    for (const node of next.head.children) {
      if (node.matches('script[src], link[rel="stylesheet"]')) continue;
      this.document.head.append(node.cloneNode(true));
    }
  }

  protected load(
    node: HTMLScriptElement | HTMLLinkElement,
    signal: AbortSignal,
  ): Promise<void> {
    return new Promise((resolve, reject) => {
      const finish = (cause?: Error) => {
        clearTimeout(timeout);
        signal.removeEventListener('abort', abort);
        node.removeEventListener('load', loaded);
        node.removeEventListener('error', failed);
        if (cause) {
          node.remove();
          reject(cause);
        } else resolve();
      };
      const abort = () =>
        finish(new DOMException('Navigation canceled.', 'AbortError'));
      const loaded = () => finish();
      const failed = () =>
        finish(new Error('A navigation asset failed to load.'));
      const timeout = setTimeout(failed, 15000);
      node.addEventListener('load', loaded, { once: true });
      node.addEventListener('error', failed, { once: true });
      signal.addEventListener('abort', abort, { once: true });
      if (signal.aborted) abort();
      else this.document.head.append(node);
    });
  }
}
