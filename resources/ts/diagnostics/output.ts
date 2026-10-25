import { record } from '../state/values';

/** Bounded development output, separate from transported island state. */
export interface DiagnosticOutput {
  output: string;
  truncated: boolean;
  viewer: boolean;
}

/** Validate optional output before it reaches the development viewer. */
export function diagnosticOutput(value: unknown): value is DiagnosticOutput {
  return (
    record(value) &&
    typeof value.output === 'string' &&
    value.output.length <= 786_432 &&
    typeof value.truncated === 'boolean' &&
    typeof value.viewer === 'boolean'
  );
}

/** Require a valid diagnostic extension when an envelope includes one. */
export function diagnosticEnvelope(value: unknown): boolean {
  return (
    record(value) &&
    (!('diagnostics' in value) || diagnosticOutput(value.diagnostics))
  );
}

const documents = new WeakMap<
  Document,
  { sequence: number; viewer?: OutputViewer }
>();

/**
 * Identifies one transport read so concurrent streams cannot mix their output.
 *
 * Creating a request does not create UI or retain response data. Only explicitly
 * enabled, validated diagnostic output starts the document-owned viewer.
 */
export class DiagnosticRequest {
  private readonly state;
  private readonly sequence: number;

  constructor(private readonly id: string) {
    let state = documents.get(document);
    if (!state) {
      state = { sequence: 0 };
      documents.set(document, state);
    }
    this.state = state;
    this.sequence = ++state.sequence;
  }

  /** Display validated output only when the server enables the built-in viewer. */
  show(value: DiagnosticOutput): void {
    if (!value.viewer) return;
    this.state.viewer ??= new OutputViewer(this.state);
    this.state.viewer.append(this.sequence, this.id, value);
  }
}

/**
 * Displays the latest diagnostic request outside island and Alpine ownership.
 *
 * Dumps become inert text inside a sandboxed frame. Styles use DOM properties,
 * require no inline stylesheet or executable dump scripts, and ship in both bundles.
 */
class OutputViewer {
  private readonly host = document.createElement('aside');
  private readonly shadow = this.host.attachShadow({ mode: 'open' });
  private readonly frame = document.createElement('iframe');
  private readonly status = document.createElement('p');
  private readonly summary = document.createElement('span');
  private readonly copyButton = control('Copy island debug', 'Copy');
  private readonly toggle = control('Close island debug', 'Hide');
  private sequence = 0;
  private output = '';
  private truncated = false;
  private frameLoading = false;
  private pendingDocument: string | undefined;

  constructor(private readonly state: { sequence: number }) {
    this.host.setAttribute('aria-label', 'Island debug');
    Object.assign(this.host.style, {
      boxSizing: 'border-box',
      display: 'block',
      position: 'fixed',
      inset: 'auto 1rem 1rem auto',
      width: 'var(--interact-debug-width, 48rem)',
      maxWidth: 'calc(100vw - 2rem)',
      maxHeight: '60vh',
      overflow: 'auto',
      zIndex: 'var(--interact-debug-z-index, 2147483647)',
      border:
        '1px solid var(--interact-debug-border-color, light-dark(#d5dbe7, #363e50))',
      borderRadius: 'var(--interact-debug-radius, 12px)',
      boxShadow: '0 16px 64px #0004, 0 2px 8px #0002',
      background: 'var(--interact-debug-background, Canvas)',
      color: 'var(--interact-debug-color, CanvasText)',
      font: '13px/1.5 var(--interact-debug-font-family, system-ui, sans-serif)',
    });
    const header = document.createElement('div');
    Object.assign(header.style, {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      flexWrap: 'wrap',
      gap: '12px',
      padding: '12px 16px',
      background:
        'var(--interact-debug-background, light-dark(#f7f8fc, #1c2331))',
    });
    const heading = document.createElement('div');
    Object.assign(heading.style, {
      display: 'flex',
      alignItems: 'center',
      gap: '8px',
    });
    const icon = document.createElement('span');
    icon.textContent = '</>';
    icon.setAttribute('aria-hidden', 'true');
    Object.assign(icon.style, {
      font: '600 12px ui-monospace, monospace',
      padding: '4px 5px',
      borderRadius: '6px',
      color: 'var(--interact-debug-accent-color, light-dark(#6550c5, #b7abff))',
      background:
        'var(--interact-debug-background, light-dark(#eeebff, #302b49))',
    });
    const title = document.createElement('strong');
    title.textContent = 'Island debug';
    title.style.fontSize = '14px';
    heading.append(icon, title);
    const actions = document.createElement('div');
    Object.assign(actions.style, { display: 'flex', gap: '6px' });
    this.copyButton.addEventListener('click', () => {
      void this.copy();
    });
    const clear = control('Clear island debug', 'Clear');
    clear.addEventListener('click', () => this.clear());
    actions.append(this.copyButton, clear, this.toggle);
    header.append(heading, actions);
    const metadata = document.createElement('div');
    Object.assign(metadata.style, {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: '12px',
      padding: '8px 16px',
      borderBlock:
        '1px solid var(--interact-debug-border-color, light-dark(#e8ebf2, #2b3344))',
      color: 'var(--interact-debug-color, light-dark(#667085, #a2aec3))',
      font: '11px/1.5 ui-monospace, monospace',
    });
    this.status.setAttribute('role', 'status');
    this.status.setAttribute('aria-live', 'polite');
    Object.assign(this.status.style, {
      margin: '0',
      overflow: 'hidden',
      textOverflow: 'ellipsis',
      whiteSpace: 'nowrap',
    });
    this.summary.style.whiteSpace = 'nowrap';
    metadata.append(this.status, this.summary);
    this.frame.title = 'Captured island output';
    this.frame.setAttribute('sandbox', '');
    this.frame.setAttribute('referrerpolicy', 'no-referrer');
    Object.assign(this.frame.style, {
      width: '100%',
      height: 'var(--interact-debug-height, min(24rem, 40vh))',
      verticalAlign: 'top',
      border: '0',
      background: 'Canvas',
    });
    this.toggle.addEventListener('click', () => {
      this.frame.hidden = !this.frame.hidden;
      this.controls();
    });
    this.frame.addEventListener('load', () => {
      this.frameLoading = false;
      this.paint();
    });
    this.shadow.append(header, metadata);
    document.addEventListener('interact:navigating', () => this.clear());
  }

  /** Append only this request's bounded output without taking application focus. */
  append(sequence: number, id: string, value: DiagnosticOutput): void {
    if (sequence < this.sequence) return;
    if (sequence !== this.sequence) {
      this.output = '';
      this.truncated = false;
      this.sequence = sequence;
      this.frame.hidden = false;
    }
    const combined = this.output + value.output;
    this.output = combined.slice(0, 786_432);
    this.truncated ||= value.truncated || combined.length > this.output.length;
    this.status.textContent = `Island ${id}${this.truncated ? ' — output truncated' : ''}`;
    this.status.title = this.status.textContent;
    this.status.style.color = this.truncated
      ? 'light-dark(#9b5700, #e8bf80)'
      : 'inherit';
    this.summary.textContent = `${(new TextEncoder().encode(this.output).length / 1024).toFixed(1)} KB`;
    this.copyButton.textContent = 'Copy';
    this.copyButton.disabled = !navigator.clipboard;
    this.copyButton.style.opacity = this.copyButton.disabled ? '0.5' : '1';
    this.copyButton.title = this.copyButton.disabled
      ? 'Copy requires a secure browser context'
      : 'Copy captured output';
    if (!this.host.isConnected) document.body.append(this.host);
    const override = getComputedStyle(this.host)
      .getPropertyValue('--interact-debug-color-scheme')
      .trim();
    const inherited = ['light', 'dark', 'light dark', 'dark light'].includes(
      override,
    )
      ? override
      : getComputedStyle(document.documentElement).colorScheme;
    const scheme =
      inherited.includes('dark') && !inherited.includes('light')
        ? 'dark'
        : inherited.includes('light') && !inherited.includes('dark')
          ? 'light'
          : 'light dark';
    this.host.style.colorScheme = scheme;
    this.frame.style.colorScheme = scheme;
    const pre = document.createElement('pre');
    pre.textContent = outputText(this.output);
    this.pendingDocument =
      `<!doctype html><meta name="color-scheme" content="${scheme}"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; base-uri &#39;none&#39;; form-action &#39;none&#39;">` +
      pre.outerHTML;
    this.paint();
    if (!this.frame.isConnected) this.shadow.append(this.frame);
    this.controls();
  }

  /** Serialize frame navigations and retain only the latest cumulative document while loading. */
  private paint(): void {
    if (this.frameLoading || this.pendingDocument === undefined) return;
    this.frameLoading = true;
    this.frame.srcdoc = this.pendingDocument;
    this.pendingDocument = undefined;
  }

  /** Release content and invalidate in-flight requests when the document changes. */
  private clear(): void {
    this.sequence = ++this.state.sequence;
    this.output = '';
    this.truncated = false;
    this.frame.remove();
    this.frameLoading = false;
    this.pendingDocument = undefined;
    this.frame.removeAttribute('srcdoc');
    this.status.textContent = '';
    this.summary.textContent = '';
    this.host.remove();
  }

  private controls(): void {
    const label = this.frame.hidden
      ? 'Open island debug'
      : 'Close island debug';
    this.toggle.textContent = this.frame.hidden ? 'Show' : 'Hide';
    this.toggle.setAttribute('aria-label', label);
    this.toggle.title = label;
    this.toggle.setAttribute('aria-expanded', String(!this.frame.hidden));
  }

  /** Copy only inert text and keep clipboard failures local to the viewer. */
  private async copy(): Promise<void> {
    try {
      await navigator.clipboard.writeText(outputText(this.output));
      this.copyButton.textContent = 'Copied';
    } catch {
      this.copyButton.textContent = 'Copy failed';
    }
  }
}

/** Build compact, labelled controls that remain usable without application styles. */
function control(label: string, text: string): HTMLButtonElement {
  const button = document.createElement('button');
  button.setAttribute('aria-label', label);
  button.textContent = text;
  button.type = 'button';
  Object.assign(button.style, {
    minHeight: '32px',
    padding: '5px 10px',
    border:
      '1px solid var(--interact-debug-border-color, light-dark(#d5dbe7, #3b4458))',
    borderRadius: '6px',
    background: 'var(--interact-debug-background, light-dark(#fff, #252e3e))',
    color: 'inherit',
    font: '500 12px/1.5 var(--interact-debug-font-family, system-ui, sans-serif)',
    cursor: 'pointer',
  });
  return button;
}

/** Extract CakePHP's formatted text in an inert template; never mount captured HTML. */
function outputText(output: string): string {
  if (!/class=["'][^"']*\bcake-debug/.test(output)) return output;
  const template = document.createElement('template');
  template.innerHTML = output;
  for (const element of template.content.querySelectorAll('script, style'))
    element.remove();
  return template.content.textContent ?? '';
}
