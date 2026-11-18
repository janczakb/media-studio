import {test} from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {fileURLToPath} from 'node:url';
const output = await build({entryPoints: [fileURLToPath(new URL('../../packages/react/src/folders/FolderNavigationClient.ts', import.meta.url))], bundle: true, write: false, format: 'esm', platform: 'node'});
const {FolderNavigationClient} = await import('data:text/javascript;base64,' + Buffer.from(output.outputFiles[0].text).toString('base64'));
const context = id => ({folderId: id, root: {id: null}, tail: [{id, name: id}], hasCollapsedAncestors: true, ancestorCursor: '0', totalDepth: null, revision: 1});
test('ancestor windows bound payloads and duplicate admission across 10,000 ancestors', async () => {
  let requests = 0;
  const client = new FolderNavigationClient('workspace', {
    context: async (_, id) => context(id),
    ancestors: async (_, id, cursor) => {
      requests++;
      const start = Number(cursor);
      return {items: Array.from({length: 50}, (_, index) => ({id: String(start + index), name: id})), nextCursor: start < 9950 ? String(start + 50) : null, revision: 1};
    },
  });
  await client.open('folder');
  for (let index = 0; index < 200; index++) {
    await Promise.all([client.loadMore(), client.loadMore()]);
    assert.ok(client.getSnapshot().pages.length <= 4);
    assert.ok(client.getSnapshot().pages.flatMap(page => page.items).length <= 200);
  }
  assert.equal(requests, 200);
  assert.equal(client.getSnapshot().pages.at(-1).items.at(-1).id, '9999');
  client.resetAncestors();
  await client.loadMore();
  assert.equal(client.getSnapshot().pages[0].items[0].id, '0');
  client.destroy();
});
test('superseded contexts and disposed reads never publish late results', async () => {
  let release, signal;
  const client = new FolderNavigationClient('workspace', {
    context: async (_, id, abort) => id === 'old' ? new Promise(resolve => {release = () => resolve(context(id)); signal = abort;}) : context(id),
    ancestors: async () => {throw new Error('unused');},
  });
  const old = client.open('old').catch(error => error.name);
  await client.open('new');
  assert.equal(signal.aborted, true);
  release();
  assert.equal(await old, 'AbortError');
  assert.equal(client.getSnapshot().context.folderId, 'new');
  client.destroy();
});
test('synchronous adapter failures remain retryable without a stuck admission slot', async () => {
  let calls = 0;
  const client = new FolderNavigationClient('workspace', {
    context: async (_, id) => context(id),
    ancestors: () => {calls++; throw new Error('retry');},
  });
  await client.open('folder');
  await client.loadMore(); await client.loadMore();
  assert.equal(calls, 2);
  assert.equal(client.getSnapshot().loading, false);
  client.destroy();
});
