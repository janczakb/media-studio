import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFile} from 'node:fs/promises';
import {transform} from 'esbuild';
const source = await readFile(new URL('../src/core/folder-window.ts', import.meta.url), 'utf8');
const {code} = await transform(source, {loader: 'ts', format: 'esm'});
const {mergeFolderPage} = await import('data:text/javascript;base64,' + Buffer.from(code).toString('base64'));
const page = n => ({items: Array.from({length: 60}, (_, i) => ({id: String(n * 60 + i), name: 'Folder ' + (n * 60 + i)})), previousCursor: n ? String(n * 60) : null, nextCursor: String((n + 1) * 60 - 1)});

test('cursor window remains bounded while preserving the forward scroll anchor', () => {
  let pages = [page(0)];
  for (let n = 1; n < 20; n++) {
    const result = mergeFolderPage(pages, page(n), 'next');
    assert.equal(result.scrollDelta, n < 6 ? 0 : -60 * 36);
    assert.ok(result.pages.length <= 6);
    assert.ok(result.pages.flatMap(p => p.items).length <= 360);
    pages = result.pages;
  }
  assert.equal(pages[0].items[0].id, String(14 * 60));
  const previous = mergeFolderPage(pages, page(13), 'previous');
  assert.equal(previous.scrollDelta, 60 * 36);
  assert.equal(previous.pages[0].items[0].id, String(13 * 60));
  assert.equal(previous.pages.at(-1).items.at(-1).id, String(19 * 60 - 1));
});

test('overlapping or exhausted responses do not duplicate rows or loop requests', () => {
  const first = page(0);
  const duplicate = mergeFolderPage([first], first, 'next');
  assert.equal(duplicate.pages.length, 1);
  assert.equal(duplicate.pages[0].nextCursor, null);
  assert.equal(duplicate.scrollDelta, 0);
  const partial = mergeFolderPage([first], {...page(1), items: [first.items[59], ...page(1).items.slice(0, 59)]}, 'next');
  const ids = partial.pages.flatMap(p => p.items.map(i => i.id));
  assert.equal(new Set(ids).size, ids.length);
  const exhausted = mergeFolderPage(partial.pages, {items: [], previousCursor: 'stale', nextCursor: 'stale'}, 'previous');
  assert.equal(exhausted.pages[0].previousCursor, null);
  assert.equal(exhausted.scrollDelta, 0);
});
