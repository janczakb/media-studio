import assert from 'node:assert/strict';
import {test} from 'node:test';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';

const {outputFiles: [compiled]} = await build({
  stdin: {
    contents: `
      export {instagramEmbedAssets,instagramVideo} from './src/content/instagram';
      export {parseSrcset,largestResponsiveImage} from './src/content/responsive-image';
    `,
    resolveDir: fileURLToPath(new URL('..', import.meta.url)),
    loader: 'ts',
  },
  bundle: true, write: false, format: 'esm', platform: 'node',
});
const {instagramEmbedAssets, instagramVideo, parseSrcset, largestResponsiveImage} = await import(
  'data:text/javascript;base64,' + Buffer.from(compiled.text).toString('base64')
);

const origin = 'https://www.instagram.com';
const cdn = 'https://scontent.example.cdninstagram.com';
const photo = (name, width, height) => ({src: `${cdn}/${name}.jpg`, config_width: width, config_height: height});
const video = (name, width, height, bitrate) => ({url: `${cdn}/${name}.mp4`, width, height, bitrate_kbps: bitrate});
const embed = media => `<script>init({"contextJSON":${JSON.stringify(JSON.stringify({
  context: {copyright_blocked: false}, gql_data: {shortcode_media: media},
}))}})</script>`;

test('responsive candidates accept URLs with commas and reject invalid descriptors', () => {
  assert.deepEqual(parseSrcset('data:image/gif;base64,AAA 1x, /crop,wide.jpg 1200w, /invalid.jpg 0w, /good.jpg 2x', origin), [
    {url: origin + '/crop,wide.jpg', size: 1200, unit: 'w'}, {url: origin + '/good.jpg', size: 2, unit: 'x'},
  ]);
});

test('picture keeps the active crop and compares densities against the source width', () => {
  globalThis.matchMedia = value => ({matches: value !== '(min-width: 1200px)'});
  const node = (attributes, previousElementSibling = null) => ({
    tagName: 'SOURCE', getAttribute: name => attributes[name] ?? null, previousElementSibling,
  });
  const other = node({media: '(min-width: 1200px)', srcset: '/other.jpg 2400w'});
  const portrait = node({media: '(max-width: 800px)', srcset: '/portrait.jpg 400w, /portrait-hd.jpg 1000w'}, other);
  const format = node({media: '(max-width: 800px)', srcset: '/portrait-2x.webp 2x'}, portrait);
  const image = {
    baseURI: origin, clientWidth: 400, parentElement: {tagName: 'PICTURE'}, previousElementSibling: format,
    getAttribute: name => name === 'srcset' ? '/fallback.jpg 2500w' : null,
  };
  assert.equal(largestResponsiveImage(image, origin + '/portrait.jpg'), origin + '/portrait-hd.jpg');
});

test('Instagram selects the largest image from a regular photo embed', () => {
  const page = new URL(origin + '/p/Photo01/');
  const html = `<img class="EmbeddedMediaImage" alt="Photo" src="${cdn}/thumb.jpg" srcset="${cdn}/thumb.jpg 320w, ${cdn}/max.jpg?signature=1&amp;test=2 1440w">`;
  const [high] = instagramEmbedAssets(html, page, true);
  assert.equal(high.url, cdn + '/max.jpg?signature=1&test=2');
  assert.equal(high.previewUrl, cdn + '/thumb.jpg');
  assert.equal(instagramEmbedAssets(html, page, false)[0].url, cdn + '/thumb.jpg');
});

test('Instagram extracts direct Reel files and selects the highest declared video resolution', () => {
  const page = new URL(origin + '/reel/Video01/');
  const media = {shortcode: 'Video01', is_video: true, video_url: cdn + '/direct.mp4',
    video_versions: [video('small', 480, 720, 600), video('low-bitrate', 720, 1080, 750), video('highest', 720, 1080, 1200)]};
  const [asset] = instagramEmbedAssets(embed(media), page, true);
  assert.equal(asset.kind, 'video');
  assert.equal(asset.url, cdn + '/highest.mp4');
  assert.equal(asset.fallbackUrl, cdn + '/direct.mp4');
  assert.deepEqual([asset.width, asset.height], [720, 1080]);
  assert.equal(instagramEmbedAssets(embed({...media, shortcode: 'OtherId'}), page, true).length, 0);
});

test('mixed Instagram carousels keep order and quick save resolves only the chosen slide', async context => {
  const page = new URL(origin + '/p/Mixed01/?img_index=2');
  const media = {shortcode: 'Mixed01', edge_sidecar_to_children: {edges: [
    {node: {is_video: false, display_url: cdn + '/preview.jpg', dimensions: {width: 1200, height: 1600},
      display_resources: [photo('preview', 600, 800), photo('crop', 2000, 2000), photo('full', 1200, 1600)]}},
    {node: {is_video: true, video_url: cdn + '/reel.mp4'}},
    {node: {is_video: true, video_url: 'https://unknown.example/video.mp4'}},
  ]}};
  const html = embed(media);
  const assets = instagramEmbedAssets(html, page, true);
  assert.deepEqual(assets.map(asset => asset.url), [cdn + '/full.jpg', cdn + '/reel.mp4']);
  assert.deepEqual(assets.map(asset => new URL(asset.pageUrl).searchParams.get('img_index')), ['1', '2']);

  const fetch = context.mock.method(globalThis, 'fetch', async () => new Response(html, {headers: {'Content-Type': 'text/html'}}));
  assert.equal((await instagramVideo(page, true)).url, cdn + '/reel.mp4');
  assert.equal(await instagramVideo(new URL(origin + '/p/Mixed01/'), true), null);
  assert.equal(fetch.mock.callCount(), 1);
  const [address, options] = fetch.mock.calls[0].arguments;
  assert.equal(address.href, origin + '/p/Mixed01/embed/');
  assert.equal(options.credentials, 'omit');
});
