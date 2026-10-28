import { describe, expect, it } from 'vite-plus/test';
import { newerWritable } from '../../../resources/ts/state/drafts';
import { mergeChanges } from '../../../resources/ts/state/paths';
import type { Json } from '../../../resources/ts/types';

describe('newer array drafts', () => {
  it.each<[string, Json, Json, Json, Json]>([
    [
      'independent map fields',
      { title: 'old', comments: [] },
      { title: 'typed', comments: [] },
      { title: 'old', comments: [{ body: '' }] },
      { title: 'typed', comments: [{ body: '' }] },
    ],
    [
      'nested server fields',
      { address: { city: 'old', postcode: '' } },
      { address: { city: 'typed', postcode: '' } },
      { address: { city: 'old', postcode: '1000' } },
      { address: { city: 'typed', postcode: '1000' } },
    ],
    [
      'local conflicts',
      { title: 'old' },
      { title: 'typed' },
      { title: 'normalized' },
      { title: 'typed' },
    ],
    [
      'map removals and additions',
      { remove: false, keep: null },
      { keep: null, added: '' },
      { remove: true, keep: 0, server: false },
      { keep: 0, added: '', server: false },
    ],
    [
      'empty PHP arrays becoming maps',
      [],
      { title: 'typed' },
      { server: 'new' },
      { title: 'typed', server: 'new' },
    ],
    [
      'server list appends',
      [{ body: 'old' }],
      [{ body: 'typed' }],
      [{ body: 'old' }, { body: '' }],
      [{ body: 'typed' }, { body: '' }],
    ],
    [
      'conflicting list removals',
      ['one', 'two'],
      ['typed', 'two'],
      ['two'],
      ['typed', 'two'],
    ],
    [
      'conflicting list reorders',
      ['one', 'two'],
      ['typed', 'two'],
      ['two', 'one'],
      ['typed', 'two'],
    ],
    [
      'local list replacements',
      ['one'],
      ['one', 'local'],
      ['one', 'server'],
      ['one', 'local'],
    ],
    [
      'server map replacement',
      { title: 'old' },
      { title: 'typed' },
      null,
      { title: 'typed' },
    ],
    [
      'local scalar replacements',
      { title: 'old' },
      null,
      { title: 'server' },
      null,
    ],
  ])('preserves %s', (_scenario, sent, current, accepted, expected) => {
    const original = structuredClone({ sent, current, accepted });
    const fields = { data: { type: 'array' as const, nullable: true } };
    const result = mergeChanges(
      { data: accepted },
      newerWritable(
        { data: current },
        { data: sent },
        { data: accepted },
        fields,
      ),
    );
    expect(result).toEqual({ data: expected });
    expect({ sent, current, accepted }).toEqual(original);
  });

  it('does not preserve unchanged or locked state', () => {
    expect(
      newerWritable(
        { data: { title: 'old' }, locked: 'local' },
        { data: { title: 'old' }, locked: 'old' },
        { data: { title: 'server' }, locked: 'server' },
        { data: { type: 'array', nullable: false } },
      ),
    ).toEqual({});
  });
});
