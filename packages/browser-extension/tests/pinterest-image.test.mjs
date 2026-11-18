import assert from 'node:assert/strict';
import {test} from 'node:test';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';

const {outputFiles: [compiled]} = await build({
  stdin: {
    contents: `
      export {pinterestOriginal} from './src/core/pinterest-image';
      export {downloadWithFallback, fileName} from './src/background/download';
    `,
    resolveDir: fileURLToPath(new URL('..', import.meta.url)),
    loader: 'ts',
  },
  bundle: true,
  write: false,
  format: 'esm',
  platform: 'node',
});
globalThis.chrome = {permissions: {contains: async () => true}};
const {pinterestOriginal, downloadWithFallback, fileName} = await import(
  'data:text/javascript;base64,' + Buffer.from(compiled.text).toString('base64')
);
const path = '/17/34/8e/17348e163a3212c06e61c41c4b22b87a';
const cdn = (rendition, format) => `https://i.pinimg.com/${rendition}${path}.${format}`;
const preview = cdn('236x', 'webp');
const fallback = cdn('736x', 'jpg');
const primary = cdn('originals', 'webp');
const capture = {
  kind: 'image', url: primary, previewUrl: preview, fallbackUrl: fallback,
  pageUrl: 'https://www.pinterest.com/pin/1/', name: 'Photo.webp',
};

test('Pinterest normalization handles CDN experiments and leaves avatars and lookalike hosts alone', () => {
  assert.equal(pinterestOriginal(cdn('236x/enabled_hi/vwebp', 'webp') + '?test=1'), primary);
  assert.equal(pinterestOriginal(cdn('75x75_RS', 'jpg')), null);
  assert.equal(pinterestOriginal(preview.replace('i.pinimg.com', 'i.pinimg.com.example.test')), null);
  assert.equal(pinterestOriginal(cdn('236x/unknown', 'webp')), null);
});

test('an alternate original wins over every preview', async context => {
  const mock = context.mock.method(globalThis, 'fetch', async url =>
    url === cdn('originals', 'jpg')
      ? new Response(new Uint8Array([255, 216, 255]), {headers: {'Content-Type': 'image/webp'}})
      : new Response(null, {status: 403})
  );
  const blob = await downloadWithFallback(capture, 512, new AbortController().signal, () => {}, false);
  assert.equal(blob.type, 'image/jpeg');
  assert.equal(fileName(capture, blob), 'Photo.jpg');
  assert.deepEqual(mock.mock.calls.map(call => call.arguments[0]), [primary, cdn('originals', 'jpg')]);
});

test('if originals fail, use the largest rendition observed on the page before the thumbnail', async context => {
  const mock = context.mock.method(globalThis, 'fetch', async url => {
    if (url === fallback) return new Response(new Uint8Array([255, 216, 255, 1]), {headers: {'Content-Type': 'image/jpeg'}});
    if (url === primary) return new Response('<html>', {headers: {'Content-Type': 'text/html'}});
    return new Response(null, {status: 404});
  });
  const blob = await downloadWithFallback(capture, 512, new AbortController().signal, () => {}, false);
  assert.deepEqual(new Uint8Array(await blob.arrayBuffer()), new Uint8Array([255, 216, 255, 1]));
  assert.deepEqual(mock.mock.calls.map(call => call.arguments[0]), [
    primary, cdn('originals', 'jpg'), cdn('originals', 'png'), cdn('originals', 'gif'), fallback,
  ]);
});

test('the download limit remains enforced on the original', async context => {
  const mock = context.mock.method(globalThis, 'fetch', async () => new Response('oversized', {
    headers: {'Content-Type': 'image/webp', 'Content-Length': '9'},
  }));
  await assert.rejects(downloadWithFallback(capture, 4, new AbortController().signal, () => {}, false), /download limit/);
  assert.equal(mock.mock.callCount(), 1);
});
