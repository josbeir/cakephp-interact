import { destination } from '../effects/browser';
import { headEntries, stylesheet, trackedAssets } from './head';

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

/** Preserve a relative resource's resolved URL when history changes the document base. */
function stableUrls(node: Element, source: Element, base: string): void {
  for (const name of ['src', 'href']) {
    const value = source.getAttribute(name);
    if (value !== null && !/^(?:[a-z][\w+.-]*:|\/)/i.test(value))
      node.setAttribute(name, new URL(value, base).href);
  }
}

/**
 * Reconciles server-declared head elements under the original document's security policy.
 * Styles stage before commit; scripts execute once before incoming Alpine initialization.
 */
export class PageAssets {
  protected readonly scripts = new Map<string, HTMLScriptElement>();
  protected managed: Set<Element>;

  constructor(
    protected readonly document: Document,
    protected readonly attribute = 'x-interact:asset',
  ) {
    this.managed = new Set(document.head.children);
    for (const script of document.querySelectorAll<HTMLScriptElement>(
      'script[src]',
    ))
      this.scripts.set(key(script, document.baseURI), script);
  }

  async prepare(
    next: Document,
    url: string,
    signal: AbortSignal,
  ): Promise<() => void> {
    const base = this.document.baseURI;
    const before = headEntries(
      [...this.managed].filter(
        (node) => node.parentNode === this.document.head,
      ),
      base,
      this.attribute,
    );
    const after = headEntries(next.head.children, url, this.attribute);
    if (trackedAssets(before) !== trackedAssets(after))
      throw new Error('Tracked head assets require native navigation.');
    const scripts = [
      ...next.querySelectorAll<HTMLScriptElement>('script'),
    ].filter(executable);
    if (
      scripts.some(
        (script) => !script.hasAttribute('src') && script.textContent?.trim(),
      )
    )
      throw new Error('Executable inline scripts require native navigation.');

    // Buckets with cursors preserve duplicate declarations without repeated head scans.
    const matches = new Map<string, { nodes: Element[]; index: number }>();
    for (const { node, signature } of before) {
      const bucket = matches.get(signature);
      if (bucket) bucket.nodes.push(node);
      else matches.set(signature, { nodes: [node], index: 0 });
    }
    const desired: Element[] = [];
    const staged: { link: HTMLLinkElement; media: string | null }[] = [];
    const loading = new AbortController();
    const discard = () => {
      loading.abort();
      for (const { link } of staged) link.remove();
    };
    signal.addEventListener('abort', discard, { once: true });
    try {
      signal.throwIfAborted();
      for (const { node, signature } of after) {
        const bucket = matches.get(signature);
        const existing = bucket?.nodes[bucket.index];
        if (existing && bucket) {
          bucket.index++;
          desired.push(existing);
          continue;
        }
        const clone = node.cloneNode(true);
        if (!(clone instanceof Element))
          throw new Error('Invalid head element.');
        if (stylesheet(clone)) {
          clone.href = new URL(clone.getAttribute('href') ?? '', url).href;
          if (!destination(clone.href)) throw new Error('Invalid stylesheet.');
          const media = clone.getAttribute('media');
          clone.media = 'not all';
          staged.push({ link: clone, media });
        }
        desired.push(clone);
      }
      // Downloads overlap; no staged style participates in the active cascade yet.
      await Promise.all(
        staged.map(({ link }) =>
          link.hasAttribute('disabled') || link.relList.contains('alternate')
            ? Promise.resolve()
            : this.load(link, loading.signal),
        ),
      );
      for (const script of scripts) {
        if (!script.hasAttribute('src') || script.noModule) continue;
        const identity = key(script, url);
        if (this.scripts.has(identity)) continue;
        const clone = this.document.createElement('script');
        for (const attribute of script.attributes)
          clone.setAttribute(attribute.name, attribute.value);
        clone.nonce = script.nonce;
        clone.src = new URL(script.getAttribute('src') ?? '', url).href;
        if (!destination(clone.src))
          throw new Error('Invalid script destination.');
        clone.async = false;
        await this.load(clone, loading.signal);
        this.scripts.set(identity, clone);
      }
      // Use already executed script elements, rather than inserting fresh executable clones.
      const used = new Set(desired);
      for (const [index, node] of desired.entries()) {
        if (
          node instanceof HTMLScriptElement &&
          node.hasAttribute('src') &&
          executable(node)
        ) {
          const loaded = this.scripts.get(key(node, url));
          if (loaded && !used.has(loaded)) {
            desired[index] = loaded;
            used.add(loaded);
          }
        }
      }
      signal.throwIfAborted();
      return () => {
        signal.throwIfAborted();
        signal.removeEventListener('abort', discard);
        const ids = new Set(after.map(({ node }) => node.id).filter(Boolean));
        const retained = before
          .filter(
            ({ node, policy }) => policy === 'persist' && !ids.has(node.id),
          )
          .map(({ node }) => node);
        const ordered = [...retained, ...desired];
        for (const node of retained) stableUrls(node, node, base);
        for (const [index, node] of desired.entries()) {
          const source = after[index]?.node;
          if (source) stableUrls(node, source, url);
        }
        const keep = new Set(ordered);
        for (const node of this.managed) if (!keep.has(node)) node.remove();
        // Move only out-of-order nodes; runtime-injected head nodes follow the server declarations.
        let cursor = this.document.head.firstElementChild;
        for (const node of ordered) {
          if (node === cursor) cursor = cursor.nextElementSibling;
          else this.document.head.insertBefore(node, cursor);
        }
        for (const { link, media } of staged) {
          if (media === null) link.removeAttribute('media');
          else link.media = media;
        }
        this.managed = keep;
      };
    } catch (cause: unknown) {
      signal.removeEventListener('abort', discard);
      discard();
      throw cause;
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
