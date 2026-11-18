import assert from 'node:assert/strict';
import {execFile} from 'node:child_process';
import {mkdtemp, rm, writeFile} from 'node:fs/promises';
import {createServer} from 'node:http';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {promisify} from 'node:util';
import test from 'node:test';

const cli = fileURLToPath(new URL('../../packages/cli/cli.mjs', import.meta.url));
const workspace = '01J9GQ4ZPS9Z91FMY3JC5M8HJX';
const run = promisify(execFile);

function pngHeader(width, height) {
  const header = Buffer.alloc(24);
  Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex').copy(header);
  header.writeUInt32BE(width, 16);
  header.writeUInt32BE(height, 20);
  return header;
}

async function fixture(t) {
  const root = await mkdtemp(join(tmpdir(), 'flex-cli-test-'));
  t.after(() => rm(root, {recursive: true, force: true}));
  await writeFile(join(root, 'chair-400.png'), pngHeader(400, 300));
  await writeFile(join(root, 'chair-2400.png'), pngHeader(2400, 1800));
  return root;
}

async function server(t, handle) {
  const calls = [];
  const backend = createServer(async (request, response) => {
    const pieces = [];
    for await (const data of request) pieces.push(data);
    const bytes = Buffer.concat(pieces);
    try {
      assert.equal(request.headers.authorization, 'Bearer integration-token');
      if (request.url === '/mcp') {
        const rpc = JSON.parse(bytes);
        const {name, arguments: args} = rpc.params;
        calls.push({name, args});
        const payload = handle(name, args);
        response.setHeader('content-type', 'application/json');
        response.end(JSON.stringify({jsonrpc: '2.0', id: rpc.id, result: {structuredContent: payload}}));
        return;
      }
      calls.push({path: request.url, headers: request.headers, bytes});
      response.setHeader('content-type', 'application/json');
      response.end(JSON.stringify({id: 'imported-entry'}));
    } catch (error) {
      response.statusCode = 500;
      response.end(error.message);
    }
  });
  await new Promise(resolve => backend.listen(0, '127.0.0.1', resolve));
  t.after(() => backend.close());
  return {calls, env: {...process.env, FLEX_STUDIO_URL: 'http://127.0.0.1:' + backend.address().port + '/mcp', FLEX_STUDIO_TOKEN: 'integration-token'}};
}

test('dry run chooses one source by dimensions and blocks ambiguous crops', async t => {
  const root = await fixture(t);
  const args = [cli, 'import', root, '--pattern', '^(?<key>.+)-(?<variant>[0-9]+)\\.png$', '--dry-run', '--json'];
  const {stdout} = await run(process.execPath, args, {env: {...process.env, FLEX_STUDIO_URL: '', FLEX_STUDIO_TOKEN: ''}});
  const group = JSON.parse(stdout);
  assert.equal(group.source, 'chair-2400.png');
  assert.equal(group.target, 'chair.png');
  assert.deepEqual(group.skipped, ['chair-400.png']);
  await writeFile(join(root, 'chair-400.png'), pngHeader(400, 400));
  await assert.rejects(run(process.execPath, args), /different aspect ratios/);
});

test('keep sends one multipart bundle with named variants and display roles', async t => {
  const root = await fixture(t);
  const manifest = join(root, 'manifest.jsonl');
  await writeFile(manifest, JSON.stringify({id: 'chair', target: 'chair.png', files: [
    {path: 'chair-2400.png', original: true}, {path: 'chair-400.png', name: 'card'},
  ], roles: {thumbnail: 'card'}}));
  const {env, calls} = await server(t, name => {
    if (name === 'studio_get_upload_policy') return {enabled: false, ordinaryMaxBytes: 1000000};
    if (name === 'studio_prepare_image_import') return {uploadPath: '/bundle'};
    throw new Error('Unexpected tool ' + name);
  });
  const args = [cli, 'import', root, '--manifest', manifest, '--mode', 'keep', '--workspace', workspace, '--json'];
  assert.equal(JSON.parse((await run(process.execPath, args, {env})).stdout).entry, 'imported-entry');
  assert.equal(JSON.parse((await run(process.execPath, args, {env})).stdout).entry, 'imported-entry');
  const transfers = calls.filter(call => call.path === '/bundle');
  assert.equal(transfers.length, 2);
  assert.equal(transfers[0].headers['idempotency-key'], transfers[1].headers['idempotency-key']);
  assert.equal(Number(transfers[0].headers['content-length']), transfers[0].bytes.length);
  const multipart = transfers[0].bytes.toString();
  assert.match(multipart, /name="file"; filename="chair.png"/);
  assert.match(multipart, /name="variants\[card\]"; filename="chair-400.png"/);
  assert.match(multipart, /name="roles\[thumbnail\]"\r\n\r\ncard/);
});

test('rebuild resumes the ordinary source and skips accepted chunks', async t => {
  const root = await fixture(t);
  const manifest = join(root, 'manifest.jsonl');
  await writeFile(join(root, 'chair-2400.png'), Buffer.alloc(65540, 42));
  await writeFile(manifest, JSON.stringify({id: 'chair', files: [
    {path: 'chair-2400.png', original: true}, {path: 'chair-400.png', name: 'card'},
  ]}));
  const {env, calls} = await server(t, name => {
    if (name === 'studio_get_upload_policy') return {enabled: true};
    if (name === 'studio_begin_upload') return {state: 'uploading', id: 'session', transport: 'local', acceptedParts: [0], chunkBytes: 65536, partPathPrefix: '/parts/'};
    if (name === 'studio_complete_upload') return {id: 'imported-entry'};
    throw new Error('Unexpected tool ' + name);
  });
  const args = [cli, 'import', root, '--manifest', manifest, '--workspace', workspace, '--json'];
  assert.equal(JSON.parse((await run(process.execPath, args, {env})).stdout).entry, 'imported-entry');
  const chunks = calls.filter(call => call.path);
  assert.deepEqual(chunks.map(chunk => chunk.path), ['/parts/1']);
  assert.deepEqual(chunks[0].bytes, Buffer.alloc(4, 42));
  assert.match(chunks[0].headers['x-part-sha256'], /^[0-9a-f]{64}$/);
});
