import {localeAssets} from './locale-assets.mjs';
import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium, expect} from '@playwright/test';

let browser, server, origin;
before(async () => {
  const assets = new Map(await Promise.all([
    ['studio.js', 'text/javascript'], ['studio.css', 'text/css'], ['filament.svg', 'image/svg+xml'], ['studio-mark.svg', 'image/svg+xml'],
  ].map(async ([name, type]) => [`/${name}`, {type, body: await readFile(new URL(`../../packages/react/dist/${name}`, import.meta.url))}])));
  for(const [name,value]of await localeAssets({raw:false}))assets.set(name,value);
  server = createServer((request, response) => {
    const asset = assets.get(new URL(request.url, 'http://localhost').pathname);
    response.writeHead(200, {'Content-Type': asset?.type ?? 'text/html'});
    response.end(asset?.body ?? '<!doctype html><html><body style="margin:0"><div id="studio" style="height:100vh"></div></body></html>');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = `http://127.0.0.1:${server.address().port}`;
  browser = await chromium.launch();
});
after(async () => {await browser?.close(); await new Promise(resolve => server?.close(resolve));});

async function preview(theme) {
  const page = await browser.newPage({viewport: {width: 1440, height: 900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(origin);
  await page.evaluate(async theme => {
    const {mountMediaStudio} = await import('/studio.js');
    const workspace = {id: 'brand', name: 'Brand assets', role: 'owner'};
    const entry = {id: 'photo', name: 'Portrait.svg', kind: 'file', mediaType: 'image', mimeType: 'image/svg+xml', size: 1024, versionId: 'v1', updatedAt: '2026-11-07T00:00:00Z', revision: 1, trashedAt: null, thumbnailUrl: '/filament.svg'};
    window.controlWrites = [];
    window.controlInstance = mountMediaStudio(document.querySelector('#studio'), {theme, transport: {
      async workspaces() {return {items: [workspace], canCreate: false};},
      async browse() {return {items: [entry], nextCursor: null, previousCursor: null, hasMore: false, canWrite: true};},
      async details() {return {...entry, parentId: null, createdAt: entry.updatedAt, metadata: {alt: 'Original alt', caption: 'Original caption', description: 'Original description'}};},
      async settings() {return {revision: 0, extensions: ['svg'], groups: {image: ['svg', 'webp']}, chunks: true, chunksAvailable: true, canManage: true,
        chunkMaxMiB: null, transferPolicy: {serverMaxMiB: 1024, chunkBytes: 5242880, maxParts: 1000, maxConcurrent: 3, multipartThresholdBytes: null}};},
      async updateSettings(workspace, value) {window.controlWrites.push({type: 'settings', workspace, ...value}); return {...await this.settings(), ...value, revision: 1};},
      async createFolder(workspace, parent, name) {window.controlWrites.push({type: 'folder', workspace, parent, name}); return {...entry, id: 'folder', name, kind: 'folder'};},
      async update(workspace, id, values) {window.controlWrites.push({type: 'update', workspace, id, values}); return {...entry, ...values, revision: entry.revision + 1};},
      async upload() {throw new Error('Not used');},
      contentUrl() {throw new Error('Metadata controls must not request original files');},
    }});
  }, theme);
  await expect(page.locator('.fms-title-row h1')).toHaveText('All files');
  return {page, errors};
}

async function expectSecondary(field) {
  await expect(field).toHaveClass(/(?:input|textarea)--secondary/);
  await expect(field).toBeVisible();
  const appearance = await field.evaluate(element => {
    const style = getComputedStyle(element);
    const modal = element.closest('[role="dialog"]');
    return {background: style.backgroundColor, foreground: style.color, opacity: style.opacity, surface: modal && getComputedStyle(modal).backgroundColor};
  });
  assert.notEqual(appearance.background, 'rgba(0, 0, 0, 0)');
  assert.notEqual(appearance.background, appearance.surface);
  assert.notEqual(appearance.foreground, appearance.background);
  assert.equal(appearance.opacity, '1');
}

for (const theme of ['light', 'dark']) {
  test(`${theme} dialogs use native HeroUI inputs and preserve folder/metadata submission`, async () => {
    const {page, errors} = await preview(theme);
    try {
      await page.locator('.fms-library-actions').getByRole('button', {name: 'Create', exact: true}).click();
      await page.getByRole('menuitem', {name: 'New folder', exact: true}).click();
      const folderDialog = page.getByRole('dialog');
      const folderName = folderDialog.getByRole('textbox', {name: 'Name', exact: true});
      await expect(folderName).toHaveAttribute('data-slot', 'input');
      await folderName.fill('Campaign photos');
      await folderDialog.getByRole('button', {name: 'Save', exact: true}).click();
      await expect(folderDialog).toHaveCount(0);
      await expect.poll(() => page.evaluate(() => window.controlWrites[0])).toEqual({type: 'folder', workspace: 'brand', parent: null, name: 'Campaign photos'});

      await page.getByRole('button', {name: 'More actions: Portrait.svg', exact: true}).click();
      await page.getByRole('menuitem', {name: 'File information', exact: true}).click();
      const inspector = page.getByRole('dialog');
      await expect(inspector.locator('.fms-inspector-form')).toBeVisible();
      await expect(inspector.getByRole('textbox', {name: /^(Name|Description)/})).toHaveCount(0);
      const values = {'Alternative text': 'A portrait of Alex', 'Caption': 'New caption'};
      for (const [name, value] of Object.entries(values)) {
        const field = inspector.getByRole('textbox', {name, exact: true});
        await expectSecondary(field);
        await field.fill(value);
      }
      await inspector.getByRole('button', {name: 'Save changes', exact: true}).click();
      await expect.poll(() => page.evaluate(() => window.controlWrites.find(write => write.type === 'update'))).toEqual({type: 'update', workspace: 'brand', id: 'photo', values: {revision: 1, metadata: {alt: values['Alternative text'], caption: values.Caption}}});
      assert.deepEqual(errors, []);
    } finally {await page.close();}
  });
}


for (const width of [1440, 390]) {
  test(`settings selects are native HeroUI, clickable and centered at ${width}px`, async () => {
    const {page, errors} = await preview('light');
    try {
      await page.setViewportSize({width, height: 900});
      await page.locator('.fms-rail').getByRole('button', {name: 'Settings', exact: true}).click();
      const dialog = page.locator('.fms-settings-dialog');
      await expect(dialog).toBeVisible();
      await expect.poll(async () => Math.round((await dialog.boundingBox()).height)).toBe(440);
      const rectangle = await dialog.boundingBox();
      assert.ok(rectangle.width <= 872);
      assert.ok(Math.abs(rectangle.y + rectangle.height / 2 - 450) <= 1);
      const language = dialog.getByRole('button', {name: /Language/});
      await expect(language).toHaveClass(/select__trigger/);
      await expect(dialog.locator('.select')).toHaveClass(/select--secondary/);
      const button = await language.boundingBox();
      const arrow = await language.locator('.select__indicator').boundingBox();
      assert.ok(Math.abs(button.y + button.height / 2 - arrow.y - arrow.height / 2) <= 1);
      await language.click();
      await page.getByRole('option', {name: 'Polski', exact: true}).click();
      await expect(dialog.getByRole('button', {name: /Język/})).toHaveText('Polski');
      await dialog.getByRole('button', {name: /Język/}).click();
      await page.getByRole('option', {name: 'English', exact: true}).click();
      await dialog.getByRole('button', {name: 'Appearance', exact: true}).click();
      await dialog.getByRole('button', {name: /Appearance/}).filter({has: page.locator('.select__value')}).click();
      await page.getByRole('option', {name: 'Dark mode', exact: true}).click();
      await expect(page.locator('.fms-root')).toHaveClass(/dark/);
      await dialog.getByRole('button', {name: 'Uploads', exact: true}).click();
      const limit = dialog.getByRole('spinbutton');
      await expect(limit).toHaveClass(/input--secondary/);
      await limit.fill('16');
      await dialog.getByRole('button', {name: 'Save changes', exact: true}).click();
      await expect.poll(() => page.evaluate(() => window.controlWrites.find(write => write.type === 'settings')?.chunkMaxMiB)).toBe(16);
      assert.deepEqual(errors, []);
    } finally {
      await page.close();
    }
  });
}
