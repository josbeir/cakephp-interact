import { afterEach, describe, expect, it, vi } from 'vitest';
import { mountClient } from './mount';
import type { Fetcher, IslandResponse } from '../../resources/ts/types';
import type {
  UploadMetadata,
  Uploader,
} from '../../resources/ts/uploads/types';
import { record } from '../../resources/ts/state/values';
import { uploadMetadata } from '../../resources/ts/uploads/protocol';
import { requestError } from '../../resources/ts/transport/failure';

const mounted: ReturnType<typeof mountClient>[] = [];
afterEach(() => {
  for (const client of mounted.splice(0)) client.dispose();
  document.body.innerHTML = '';
});

function markup(): string {
  return `<form id="files" x-interact:submit="$server.call('save')">
    <input name="title" aria-label="Title" x-interact:model="title">
    <input name="document" aria-label="Document" type="file" required x-interact:model="document">
    <input name="attachments" aria-label="Attachments" type="file" multiple x-interact:model="attachments">
    <button>Save</button></form>`;
}

function setup() {
  let metadata: UploadMetadata = {
    document: { multiple: false, maxSize: 10485760, files: [] },
    attachments: { multiple: true, maxSize: 10485760, files: [] },
  };
  const root = document.createElement('section');
  root.dataset.interactId = 'files';
  root.dataset.interactSnapshot = 'initial';
  root.dataset.interactCsrf = 'csrf';
  root.dataset.interactState = JSON.stringify({ title: '' });
  root.dataset.interactWritable = JSON.stringify({
    title: { type: 'string', nullable: false },
  });
  root.dataset.interactUploads = JSON.stringify(metadata);
  root.dataset.interactEndpoint = '/_interact/update';
  root.innerHTML = markup();
  document.body.append(root);
  let nextId = 0;
  const reply = (
    uploads = metadata,
    errors: Record<string, string[]> = {},
  ): Response => {
    const data: IslandResponse = {
      version: 1,
      id: 'files',
      html: `<section data-interact-id="files">${markup()}</section>`,
      snapshot: 'accepted',
      state: { title: '' },
      writable: { title: { type: 'string', nullable: false } },
      uploads,
      errors,
      events: [],
    };
    return new Response(JSON.stringify(data), {
      status: Object.keys(errors).length ? 422 : 200,
      headers: { 'Content-Type': 'application/json' },
    });
  };
  const uploader = vi.fn<Uploader>(
    async (_url, _csrf, payload, file, signal, progress) => {
      if (signal.aborted) throw requestError('cancelled', null);
      if (
        !record(payload) ||
        !record(payload.upload) ||
        typeof payload.upload.field !== 'string'
      )
        throw new Error('Invalid test upload payload');
      const field = payload.upload.field;
      const previous = metadata[field];
      if (!previous) throw new Error('Unknown test field');
      progress(50);
      const accepted = {
        id: (++nextId).toString(16).padStart(32, '0'),
        name: file.name,
        size: file.size,
      };
      metadata = {
        ...metadata,
        [field]: {
          ...previous,
          files: previous.multiple ? [...previous.files, accepted] : [accepted],
        },
      };
      return reply();
    },
  );
  const fetcher = vi.fn<Fetcher>(async (_url, init) => {
    if (typeof init.body !== 'string') throw new Error('Expected JSON');
    const payload: unknown = JSON.parse(init.body);
    if (
      record(payload) &&
      record(payload.upload) &&
      typeof payload.upload.field === 'string'
    ) {
      const field = payload.upload.field;
      const previous = metadata[field];
      if (!previous) throw new Error('Unknown test field');
      const id = payload.upload.id;
      metadata = {
        ...metadata,
        [field]: {
          ...previous,
          files:
            id === undefined
              ? []
              : previous.files.filter((file) => file.id !== id),
        },
      };
    }
    return reply();
  });
  const client = mountClient(root, fetcher, undefined, uploader);
  mounted.push(client);
  const input = (field = 'document'): HTMLInputElement => {
    const control = root.querySelector<HTMLInputElement>(
      `input[name="${field}"]`,
    );
    if (!control) throw new Error('Missing input');
    return control;
  };
  const choose = (field = 'document', name = 'notes.txt', size = 4) => {
    const control = input(field);
    Object.defineProperty(control, 'files', {
      configurable: true,
      value: [new File([new Uint8Array(size)], name, { type: 'text/plain' })],
    });
    control.dispatchEvent(new Event('change', { bubbles: true }));
    Object.defineProperty(control, 'files', { configurable: true, value: [] });
  };
  const field = (name = 'document') => {
    const value = client.bridge.uploads[name];
    if (!value) throw new Error('Missing field');
    return value;
  };
  return { root, client, uploader, fetcher, reply, input, choose, field };
}

describe('managed file selections', () => {
  it('stages without a save, validates metadata, and exposes progress through the existing bridge', async () => {
    const value = setup();
    value.choose();
    expect(value.client.bridge.pending()).toBe(true);
    expect(value.client.bridge.pending(null)).toBe(false);
    expect(value.client.bridge.pending('save')).toBe(false);
    await vi.waitFor(() =>
      expect(value.field().files[0]?.status).toBe('ready'),
    );
    expect(value.uploader).toHaveBeenCalledOnce();
    expect(value.fetcher).not.toHaveBeenCalled();
    expect(value.field().progress).toBe(100);
    expect(value.field().pending).toBe(false);
    expect(value.input().required).toBe(false);
    expect(value.client.bridge.dirty).toBe(false);
    expect(value.client.bridge.exportDraft()).not.toContain('notes.txt');
    expect(
      uploadMetadata({
        document: {
          multiple: false,
          maxSize: 10485760,
          files: [{ id: 'a'.repeat(32), name: 'a', size: 1 }],
        },
      }),
    ).toBe(true);
  });

  it('rejects oversized selections before transfer and permits explicit correction', async () => {
    const value = setup();
    value.choose('document', 'large.txt', 12 * 1024 * 1024);
    await vi.waitFor(() =>
      expect(value.field().files[0]?.status).toBe('failed'),
    );
    expect(value.uploader).not.toHaveBeenCalled();
    expect(value.client.bridge.error).toMatchObject({
      code: 'upload_too_large',
      status: null,
    });
    expect(value.client.bridge.errors.document?.[0]).toContain('10 MiB');
    expect((await value.client.bridge.call('save')).ok).toBe(false);
    expect(value.fetcher).not.toHaveBeenCalled();
    value.choose('document', 'smaller.txt');
    await vi.waitFor(() =>
      expect(value.field().files[0]?.status).toBe('ready'),
    );
    expect(value.uploader).toHaveBeenCalledOnce();
    expect(value.client.bridge.error).toBe(null);
  });

  it.each([false, true])(
    'explains server body-limit rejection with JSON=%s',
    async (json) => {
      const value = setup();
      value.uploader.mockResolvedValue(
        new Response(
          json
            ? JSON.stringify({
                version: 1,
                error: {
                  code: 'upload_too_large',
                  message:
                    'The upload exceeds the server size limit. Choose a smaller file.',
                },
              })
            : 'Request entity too large',
          {
            status: 413,
            headers: {
              'Content-Type': json ? 'application/json' : 'text/html',
            },
          },
        ),
      );
      value.choose();
      await vi.waitFor(() =>
        expect(value.field().files[0]?.status).toBe('failed'),
      );
      expect(value.client.bridge.error).toMatchObject({
        code: 'upload_too_large',
        status: 413,
        recovery: 'retry',
      });
      expect(value.client.bridge.error?.message).toContain('size limit');
      expect(value.fetcher).not.toHaveBeenCalled();
    },
  );

  it('appends multiple selections, preserves stable item ids, and removes one or all accepted files', async () => {
    const value = setup();
    value.choose('attachments', 'one.txt');
    await vi.waitFor(() =>
      expect(value.field('attachments').files[0]?.status).toBe('ready'),
    );
    const first = value.field('attachments').files[0]?.id;
    if (!first) throw new Error('Missing first file');
    value.choose('attachments', 'two.txt');
    await vi.waitFor(() =>
      expect(value.field('attachments').files[1]?.status).toBe('ready'),
    );
    expect(value.field('attachments').files[0]?.id).toBe(first);
    await value.field('attachments').remove(first);
    expect(value.field('attachments').files.map((file) => file.name)).toEqual([
      'two.txt',
    ]);
    await value.field('attachments').remove();
    expect(value.field('attachments').files).toEqual([]);
  });

  it('replaces a single selection and restores required after removal', async () => {
    const value = setup();
    value.choose();
    await vi.waitFor(() =>
      expect(value.field().files[0]?.status).toBe('ready'),
    );
    value.choose('document', 'new.txt');
    await vi.waitFor(() =>
      expect(value.field().files).toMatchObject([
        { name: 'new.txt', status: 'ready' },
      ]),
    );
    await value.field().remove();
    expect(value.input().required).toBe(true);
  });

  it('waits for a slow transfer before executing an explicit save and retains newer text', async () => {
    const value = setup();
    let release: (() => void) | undefined;
    const normal = value.uploader.getMockImplementation();
    if (!normal) throw new Error('Missing uploader');
    value.uploader.mockImplementation(async (...args) => {
      await new Promise<void>((resolve) => {
        release = resolve;
      });
      return normal(...args);
    });
    value.choose();
    const save = value.client.bridge.call('save');
    expect(value.fetcher).not.toHaveBeenCalled();
    value.client.bridge.state.title = 'newer typing';
    release?.();
    await save;
    expect(value.fetcher).toHaveBeenCalledOnce();
    const raw = value.fetcher.mock.calls[0]?.[1].body;
    if (typeof raw !== 'string') throw new Error('Expected JSON');
    const body: unknown = JSON.parse(raw);
    expect(body).toMatchObject({
      action: 'save',
      snapshot: 'accepted',
      updates: { title: 'newer typing' },
    });
  });

  it.each(['remove', 'replace'] as const)(
    'discards a queued save when an accepted selection changes through %s',
    async (operation) => {
      const value = setup();
      value.choose();
      await vi.waitFor(() =>
        expect(value.field().files[0]?.status).toBe('ready'),
      );
      const normal = value.uploader.getMockImplementation();
      if (!normal) throw new Error('Missing uploader');
      let release: (() => void) | undefined;
      value.uploader.mockImplementationOnce(async (...args) => {
        await new Promise<void>((resolve) => {
          release = resolve;
        });
        return normal(...args);
      });
      value.choose('attachments', 'slow.txt');
      const save = value.client.bridge.call('save');
      let removed: Promise<unknown> | undefined;
      if (operation === 'remove') removed = value.field().remove();
      else value.choose('document', 'replacement.txt');
      release?.();
      expect((await save).ok).toBe(false);
      await removed;
      await vi.waitFor(() => expect(value.client.bridge.busy).toBe(false));
      const requests = value.fetcher.mock.calls.map(([, init]) => {
        if (typeof init.body !== 'string') throw new Error('Expected JSON');
        const body: unknown = JSON.parse(init.body);
        return body;
      });
      expect(requests).not.toContainEqual(
        expect.objectContaining({ action: 'save' }),
      );
      expect(value.client.bridge.paused).toBe(false);
      expect(value.client.bridge.error).toBe(null);
    },
  );

  it('does not execute a queued save after an upload failure, and retries only the file explicitly', async () => {
    const value = setup();
    const normal = value.uploader.getMockImplementation();
    if (!normal) throw new Error('Missing uploader');
    value.uploader.mockRejectedValueOnce(requestError('network_error', null));
    value.choose();
    const save = value.client.bridge.call('save');
    await save;
    await vi.waitFor(() =>
      expect(value.field().files[0]?.status).toBe('failed'),
    );
    expect(value.client.bridge.paused).toBe(true);
    expect(value.fetcher).not.toHaveBeenCalled();
    expect((await value.client.bridge.call('save')).ok).toBe(false);
    value.uploader.mockImplementation(normal);
    await value.field().retry();
    expect(value.field().files[0]?.status).toBe('ready');
    expect(value.fetcher).not.toHaveBeenCalled();
    await value.client.bridge.call('save');
    expect(value.fetcher).toHaveBeenCalledOnce();
  });

  it('holds a rejected file as field feedback and blocks saves without replaying it', async () => {
    const value = setup();
    value.uploader.mockResolvedValue(
      value.reply(undefined, { document: ['Too large'] }),
    );
    value.choose();
    await vi.waitFor(() =>
      expect(value.field().files[0]?.status).toBe('failed'),
    );
    expect(value.client.bridge.errors).toEqual({ document: ['Too large'] });
    expect(value.client.bridge.dirty).toBe(true);
    expect(value.client.bridge.paused).toBe(false);
    expect((await value.client.bridge.call('save')).ok).toBe(false);
    expect(value.fetcher).not.toHaveBeenCalled();
    await value.field().remove(value.field().files[0]?.id);
    expect(value.field().files).toEqual([]);
  });

  it('cancels active and queued selections without pausing or executing the queued save', async () => {
    const value = setup();
    value.uploader.mockImplementation(
      (_url, _csrf, _payload, _file, signal) =>
        new Promise((_resolve, reject) => {
          signal.addEventListener(
            'abort',
            () => reject(requestError('cancelled', null)),
            { once: true },
          );
        }),
    );
    value.choose();
    const save = value.client.bridge.call('save');
    value.field().cancel();
    expect((await save).ok).toBe(false);
    expect(value.field().files).toEqual([]);
    expect(value.client.bridge.error).toBeNull();
    expect(value.client.bridge.paused).toBe(false);
    expect(value.fetcher).not.toHaveBeenCalled();
  });

  it('disposes an in-flight transfer and rejects late UI updates', async () => {
    const value = setup();
    value.uploader.mockImplementation(
      (_url, _csrf, _payload, _file, signal) =>
        new Promise((_resolve, reject) =>
          signal.addEventListener('abort', () =>
            reject(requestError('cancelled', null)),
          ),
        ),
    );
    value.choose();
    const result = value.client.bridge.call('save');
    value.client.dispose();
    await result;
    expect(value.field().files).toEqual([]);
    expect(value.client.bridge.error).toBeNull();
  });

  it.each([
    null,
    [],
    {
      document: {
        multiple: false,
        maxSize: 10485760,
        files: [{ id: '../path', name: 'x', size: 1 }],
      },
    },
    {
      document: {
        multiple: false,
        maxSize: 10485760,
        files: [{ id: 'a'.repeat(32), name: 'x', size: -1 }],
      },
    },
    {
      document: {
        multiple: false,
        maxSize: 10485760,
        files: [
          { id: 'a'.repeat(32), name: 'x', size: 1 },
          { id: 'a'.repeat(32), name: 'x', size: 1 },
        ],
      },
    },
  ])('rejects malformed upload metadata %#', (metadata) => {
    expect(uploadMetadata(metadata)).toBe(false);
  });
});
