import { documentIdentity } from './document';

/** Read only CSP nonce source expressions governing script/style elements. */
export function responseNonces(headers: Headers): string[] {
  const result = new Set<string>();
  for (const name of [
    'Content-Security-Policy',
    'Content-Security-Policy-Report-Only',
  ]) {
    const policy = headers.get(name) ?? '';
    for (const directive of policy.matchAll(
      /(?:^|[;,])\s*(?:default-src|script-src(?:-elem)?|style-src(?:-elem)?)\s+([^;,]*)/gi,
    ))
      for (const source of (directive[1] ?? '').matchAll(
        /'nonce-([A-Za-z0-9+/_-]+={0,2})'/gi,
      ))
        if (source[1]) result.add(source[1]);
  }
  return [...result];
}

/** Read native nonce-hiding attributes without publishing their values in metadata content. */
function documentNonces(document: Document): string[] {
  const count = documentIdentity(document)?.nonceCount ?? 0;
  const markers = document.head.querySelectorAll<HTMLMetaElement>(
    'meta[name="interact-navigation-nonce"]',
  );
  if (markers.length !== count)
    throw new Error('Invalid navigation nonce markers.');
  const nonces = new Set<string>();
  markers.forEach((marker, index) => {
    const nonce = marker.nonce;
    if (marker.content !== String(index) || !nonce || nonces.has(nonce))
      throw new Error('Invalid navigation nonce marker.');
    nonces.add(nonce);
  });
  return [...nonces];
}

/**
 * Rewrite nonce-bearing styles inertly before the document parser evaluates their CSP.
 *
 * Chromium checks style CSP even in DOMParser documents. A temporary, unpredictable
 * attribute links rewritten style tags to their original values for later validation;
 * skipped or ambiguously parsed markup cannot acquire an active nonce capability.
 * Comments and raw-text contents stay byte-for-byte intact. No extra pass is needed
 * for the common case without rotating nonces.
 */
export function parseDocument(
  html: string,
  current: Document,
  advertised: readonly string[],
): { document: Document; originals: ReadonlyMap<Element, string> } {
  const active = documentNonces(current);
  const originals = new Map<Element, string>();
  if (!active.length || !advertised.length)
    return {
      document: new DOMParser().parseFromString(html, 'text/html'),
      originals,
    };
  const mapping = new Map(
    advertised.map((nonce, index) => [nonce, active[index]]),
  );
  const marker =
    'data-interact-nonce-' +
    [...crypto.getRandomValues(new Uint8Array(16))]
      .map((value) => value.toString(16).padStart(2, '0'))
      .join('');
  const values: string[] = [];
  const template = current.createElement('template');
  const tags =
    /<!--(?:>|->|[\s\S]*?(?:-->|--!>|$))|<![^>]*>|<\?[^>]*>|<\/?([a-z][^\s/>]*)(?:[^>"']|"[^"]*"|'[^']*')*>/gi;
  let raw: string | undefined;
  const prepared = html.replace(
    tags,
    (tag: string, name: string | undefined) => {
      const tagName = name?.toLowerCase();
      if (raw) {
        if (tag.startsWith('</') && tagName === raw) raw = undefined;
        return tag;
      }
      if (!tagName || tag.startsWith('</')) return tag;
      if (
        [
          'script',
          'style',
          'textarea',
          'title',
          'xmp',
          'iframe',
          'noembed',
          'noframes',
          'plaintext',
        ].includes(tagName)
      )
        raw = tagName;
      if (tagName !== 'style' || !/\snonce\s*=/i.test(tag)) return tag;
      template.innerHTML = tag;
      const node = template.content.firstElementChild;
      if (!node || !('nonce' in node) || typeof node.nonce !== 'string')
        return tag;
      const nonce = node.nonce;
      const replacement = mapping.get(nonce);
      if (replacement === undefined) {
        if (!active.includes(nonce)) return tag;
        node.removeAttribute('nonce');
      } else {
        node.setAttribute('nonce', replacement);
        node.setAttribute(marker, String(values.length));
        values.push(nonce);
      }
      return (
        node.outerHTML.match(
          /^<[a-z][^\s/>]*(?:[^>"']|"[^"]*"|'[^']*')*>/i,
        )?.[0] ?? tag
      );
    },
  );
  const document = new DOMParser().parseFromString(prepared, 'text/html');
  for (const node of document.querySelectorAll('[' + marker + ']')) {
    const original = values[Number(node.getAttribute(marker))];
    node.removeAttribute(marker);
    if (original !== undefined) originals.set(node, original);
  }
  return { document, originals };
}

/**
 * Map only response-authorized nonces onto the active document's existing capabilities.
 *
 * Matching opaque policy fingerprints establishes structural compatibility. Header
 * values verify the incoming markers; unrelated and pre-stamped active nonces are
 * never promoted to an executable capability.
 */
export function mapNonces(
  next: Document,
  current: Document,
  advertised: readonly string[],
  originals: ReadonlyMap<Element, string> = new Map(),
): void {
  const before = documentNonces(current);
  const after = documentNonces(next);
  if (
    before.length !== after.length ||
    after.length !== advertised.length ||
    after.some((nonce, index) => nonce !== advertised[index])
  )
    throw new Error('The destination requires a fresh browser nonce policy.');
  if (!after.length) return;
  const mapping = new Map<string, string>();
  after.forEach((nonce, index) => {
    const active = before[index];
    if (active !== undefined) mapping.set(nonce, active);
  });
  const active = new Set(before);
  for (const element of next.querySelectorAll('[nonce]')) {
    const nonce =
      originals.get(element) ??
      ('nonce' in element && typeof element.nonce === 'string'
        ? element.nonce
        : element.getAttribute('nonce'));
    const replacement = mapping.get(nonce ?? '');
    if (replacement !== undefined) element.setAttribute('nonce', replacement);
    else if (nonce && active.has(nonce)) element.removeAttribute('nonce');
  }
}
