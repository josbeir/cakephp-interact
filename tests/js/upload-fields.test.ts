import { afterEach, expect, it, vi } from 'vitest';
import { requestError } from '../../resources/ts/transport/failure';
import { UploadFields } from '../../resources/ts/uploads/fields';
import type {
  UploadJob,
  UploadMetadata,
} from '../../resources/ts/uploads/types';
import type { RequestResult } from '../../resources/ts/types';
import type { ModelBinding } from '../../resources/ts/bindings/model';

const failure: RequestResult = {
  ok: false,
  error: requestError('network_error', null).failure,
};
const active: { fields: UploadFields; finish: () => void }[] = [];
afterEach(() => {
  for (const value of active.splice(0)) {
    value.fields.dispose();
    value.finish();
  }
  document.body.innerHTML = '';
});

function fixture(
  initial: UploadMetadata = {
    document: { multiple: false, maxSize: 100, files: [] },
  },
) {
  const root = document.createElement('section');
  root.dataset.interactId = 'owner';
  const input = document.createElement('input');
  input.type = 'file';
  input.required = true;
  input.multiple = initial.document?.multiple ?? false;
  root.append(input);
  document.body.append(root);
  const transfers: {
    job: UploadJob;
    resolve: (result: RequestResult) => void;
  }[] = [];
  const submit = vi.fn(
    (job: UploadJob) =>
      new Promise<RequestResult>((resolve) => {
        transfers.push({ job, resolve });
      }),
  );
  const fields = new UploadFields(
    initial,
    (value) => value,
    submit,
    (element) => element.closest('[data-interact-id]') === root,
  );
  const field = fields.fields.document;
  if (!field) throw new Error('Missing field');
  const binding: ModelBinding = {
    path: 'document',
    timing: 'defer',
    delay: 250,
  };
  const choose = (file = new File(['text'], 'notes.txt'), change = true) => {
    Object.defineProperty(input, 'files', {
      configurable: true,
      value: [file],
    });
    if (change) input.dispatchEvent(new Event('change'));
  };
  const first = () => {
    const transfer = transfers[0];
    if (!transfer || transfer.job.kind !== 'stage')
      throw new Error('Missing transfer');
    return { ...transfer, job: transfer.job };
  };
  active.push({
    fields,
    finish: () => transfers.forEach((value) => value.resolve(failure)),
  });
  return {
    root,
    input,
    fields,
    field,
    binding,
    choose,
    first,
    transfers,
    submit,
  };
}

it('restores accepted files, clears native required, and restores it when the binding ends', () => {
  const value = fixture({
    document: {
      multiple: false,
      maxSize: 100,
      files: [{ id: 'a'.repeat(32), name: 'accepted.txt', size: 0 }],
    },
  });
  const unbind = value.fields.bind(value.input, value.binding);
  expect(value.field.files).toMatchObject([
    { name: 'accepted.txt', status: 'ready', progress: 100 },
  ]);
  expect(value.field.progress).toBe(100);
  expect(value.field.pending).toBe(false);
  expect(value.input.required).toBe(false);
  expect(value.fields.maximum('document')).toBe(100);
  expect(value.fields.maximum('missing')).toBeUndefined();
  unbind();
  expect(value.input.required).toBe(true);
  value.choose();
  expect(value.submit).not.toHaveBeenCalled();
});

it.each([
  { path: 'missing', timing: 'defer', delay: 250 },
  { path: 'document', timing: 'change', delay: 250 },
  { path: 'document', timing: 'defer', delay: 100 },
] satisfies ModelBinding[])('rejects an incompatible binding %j', (binding) => {
  const value = fixture();
  expect(() => value.fields.bind(value.input, binding)).toThrow(
    'declared upload',
  );
  expect(value.input.required).toBe(true);
  expect(value.submit).not.toHaveBeenCalled();
});

it('rejects mismatched multiplicity and duplicate binding without adding duplicate listeners', () => {
  const value = fixture();
  value.input.multiple = true;
  expect(() => value.fields.bind(value.input, value.binding)).toThrow(
    'matching multiple',
  );
  value.input.multiple = false;
  value.fields.bind(value.input, value.binding);
  expect(() => value.fields.bind(value.input, value.binding)).toThrow(
    'one upload binding',
  );
  value.choose();
  expect(value.submit).toHaveBeenCalledOnce();
});

it('ignores disabled and nested choosers while capturing a selection before a change event', () => {
  const value = fixture();
  value.fields.bind(value.input, value.binding);
  value.fields.captureAll();
  expect(value.submit).not.toHaveBeenCalled();
  value.input.disabled = true;
  value.choose();
  value.fields.captureAll();
  expect(value.submit).not.toHaveBeenCalled();
  value.input.disabled = false;
  const nested = document.createElement('section');
  nested.dataset.interactId = 'child';
  value.root.append(nested);
  nested.append(value.input);
  value.choose();
  value.fields.refresh();
  value.fields.captureAll();
  expect(value.submit).not.toHaveBeenCalled();
  value.root.append(value.input);
  value.choose(new File(['latest'], 'before-submit.txt'), false);
  value.fields.captureAll();
  expect(value.submit).toHaveBeenCalledOnce();
  expect(value.first().job.file.name).toBe('before-submit.txt');
});

it('reports unknown progress, handles zero-byte files, and ignores progress after cancellation', async () => {
  const value = fixture();
  value.fields.bind(value.input, value.binding);
  expect(value.field.progress).toBeNull();
  value.choose(new File([], 'empty.txt'));
  const transfer = value.first();
  expect(value.fields.start(transfer.job)).toBe(true);
  value.fields.progress(transfer.job, null);
  expect(value.field.progress).toBeNull();
  value.fields.progress(transfer.job, 50);
  expect(value.field.progress).toBe(50);
  value.fields.progress({ kind: 'remove', field: 'document' }, 10);
  expect(value.field.progress).toBe(50);
  value.field.cancel(transfer.job.localId);
  expect(transfer.job.signal.aborted).toBe(true);
  expect(value.fields.start(transfer.job)).toBe(false);
  value.fields.progress(transfer.job, 100);
  transfer.resolve(failure);
  await Promise.resolve();
  expect(value.field.files).toEqual([]);
  expect(value.field.progress).toBeNull();
  expect(value.fields.start({ kind: 'remove', field: 'document' })).toBe(true);
});

it('accepts PHP-owned selection changes and targets server ids for removal', async () => {
  const value = fixture();
  value.fields.bind(value.input, value.binding);
  value.fields.accept({
    document: {
      multiple: false,
      maxSize: 50,
      files: [{ id: 'b'.repeat(32), name: 'server.txt', size: 10 }],
    },
  });
  expect(value.fields.maximum('document')).toBe(50);
  expect(value.field.files[0]?.id).toBe('b'.repeat(32));
  expect(value.input.required).toBe(false);
  const removal = value.field.remove('b'.repeat(32));
  expect(value.submit).toHaveBeenLastCalledWith({
    kind: 'remove',
    field: 'document',
    id: 'b'.repeat(32),
  });
  value.transfers.at(-1)?.resolve({ ok: true });
  await removal;
  value.fields.accept({
    document: { multiple: false, maxSize: 50, files: [] },
  });
  expect(value.field.files).toEqual([]);
  expect(value.input.required).toBe(true);
  await expect(value.field.remove('unknown')).resolves.toEqual({ ok: true });
  await expect(value.field.retry('unknown')).resolves.toEqual({ ok: true });
  expect(value.submit).toHaveBeenCalledOnce();
});

it('rejects undeclared fields before changing accepted feedback', () => {
  const value = fixture();
  expect(() =>
    value.fields.accept({ other: { multiple: false, maxSize: 50, files: [] } }),
  ).toThrow('undeclared upload field');
  expect(value.field.files).toEqual([]);
});

it('retries only the requested failed selection and preserves the failure result', async () => {
  const value = fixture({
    document: { multiple: true, maxSize: 100, files: [] },
  });
  value.fields.bind(value.input, value.binding);
  value.choose(new File(['one'], 'one.txt'));
  value.choose(new File(['two'], 'two.txt'));
  for (const transfer of value.transfers) transfer.resolve(failure);
  await vi.waitFor(() =>
    expect(value.field.files.map((file) => file.status)).toEqual([
      'failed',
      'failed',
    ]),
  );
  expect(value.input.required).toBe(true);
  const id = value.field.files[1]?.id;
  if (!id) throw new Error('Missing failed selection');
  const retry = value.field.retry(id);
  expect(value.input.required).toBe(false);
  expect(value.transfers).toHaveLength(3);
  expect(value.transfers[2]?.job).toMatchObject({ kind: 'stage', localId: id });
  expect(value.field.files[0]?.status).toBe('failed');
  value.transfers[2]?.resolve(failure);
  await expect(retry).resolves.toEqual(failure);
  expect(value.input.required).toBe(true);
});

it.each([true, false])(
  'preserves authored required=%s through a failed transfer',
  async (required) => {
    const value = fixture();
    value.input.required = required;
    value.fields.bind(value.input, value.binding);
    value.choose();
    expect(value.input.required).toBe(false);
    const transfer = value.first();
    expect(value.fields.start(transfer.job)).toBe(true);
    value.fields.refresh();
    expect(value.input.required).toBe(false);
    transfer.resolve(failure);
    await vi.waitFor(() => expect(value.field.files[0]?.status).toBe('failed'));
    expect(value.input.required).toBe(required);
    expect(value.field.files).toHaveLength(1);
    value.field.cancel();
    expect(value.input.required).toBe(required);
  },
);

it('keeps a required multiple chooser satisfied when an accepted file remains beside a failed selection', async () => {
  const value = fixture({
    document: {
      multiple: true,
      maxSize: 100,
      files: [{ id: 'c'.repeat(32), name: 'accepted.txt', size: 10 }],
    },
  });
  value.fields.bind(value.input, value.binding);
  value.choose();
  value.first().resolve(failure);
  await vi.waitFor(() => expect(value.field.files[1]?.status).toBe('failed'));
  expect(value.input.required).toBe(false);
  value.fields.accept({
    document: { multiple: true, maxSize: 100, files: [] },
  });
  expect(value.field.files[0]?.status).toBe('failed');
  expect(value.input.required).toBe(true);
});
