import { expect, it } from 'vitest';
import { readMetadata } from '../../../resources/ts/transport/metadata';

function root(uploads: unknown) {
  const element = document.createElement('section');
  element.dataset.interactId = 'test';
  element.dataset.interactSnapshot = 'signed';
  element.dataset.interactEndpoint = '/update';
  element.dataset.interactUploads = JSON.stringify(uploads);
  return element;
}

it.each([
  null,
  { document: { multiple: false, maxSize: 0, maxFiles: 1, files: [] } },
  { document: { multiple: false, maxSize: '100', maxFiles: 1, files: [] } },
  ...[undefined, null, '3', 0, -1, 1.5, Number.MAX_SAFE_INTEGER + 1].map(
    (maxFiles) => ({
      document: { multiple: true, maxSize: 100, maxFiles, files: [] },
    }),
  ),
  { document: { multiple: false, maxSize: 100, maxFiles: 2, files: [] } },
  {
    document: {
      multiple: false,
      maxSize: 100,
      maxFiles: 1,
      files: [
        { id: 'a'.repeat(32), name: 'one.txt', size: 1 },
        { id: 'b'.repeat(32), name: 'two.txt', size: 1 },
      ],
    },
  },
  {
    document: {
      multiple: true,
      maxSize: 100,
      maxFiles: 10,
      files: [
        { id: 'a'.repeat(32), name: 'one.txt', size: 1 },
        { id: 'a'.repeat(32), name: 'one.txt', size: 1 },
      ],
    },
  },
])('rejects malformed initial upload declarations %#', (uploads) => {
  expect(() => readMetadata(root(uploads))).toThrow(
    'Invalid initial upload metadata',
  );
});

it('accepts initial upload declarations without requiring ordinary state or a CSRF attribute', () => {
  const uploads = {
    document: { multiple: false, maxSize: 100, maxFiles: 1, files: [] },
  };
  expect(readMetadata(root(uploads))).toMatchObject({
    uploads,
    state: {},
    writable: {},
    csrf: '',
  });
});

it.each(['interactId', 'interactSnapshot'])(
  'requires the %s identity attribute even with valid upload metadata',
  (attribute) => {
    const element = root({});
    delete element.dataset[attribute];
    expect(() => readMetadata(element)).toThrow('Missing island identity');
  },
);

it('rejects unsafe bootstrap state and cross-origin endpoints before starting any transfer', () => {
  const element = root({});
  element.dataset.interactState = 'null';
  expect(() => readMetadata(element)).toThrow('Invalid initial island state');
  element.dataset.interactState = '{}';
  element.dataset.interactEndpoint = 'https://untrusted.example/update';
  expect(() => readMetadata(element)).toThrow('same-origin');
});

it('rejects prototype keys in JSON upload metadata', () => {
  const element = root({});
  element.dataset.interactUploads =
    '{"__proto__":{"multiple":false,"maxSize":100,"maxFiles":1,"files":[]}}';
  expect(() => readMetadata(element)).toThrow(
    'Invalid initial upload metadata',
  );
});
