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

async function preview(width = 1440, {twoInstances = false, user = true} = {}) {
  const page = await browser.newPage({viewport: {width, height: 900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(origin);
  await page.evaluate(async ({twoInstances, user}) => {
    const {mountMediaStudio} = await import('/studio.js');
    window.headerRequests = [];
    window.headerThemeChanges = [];
    const workspace = {id: 'brand', name: 'Brand assets', role: 'owner'};
    const base = {kind: 'file', mediaType: 'image', mimeType: 'image/svg+xml', size: 1024, updatedAt: '2026-11-07T00:00:00Z', revision: 1, trashedAt: null, width: 320, height: 180};
    const files = Array.from({length: 60}, (_, index) => ({...base, id: `photo-${index}`, name: `Photo ${String(index).padStart(2, '0')}.svg`, versionId: `v${index}`, thumbnailUrl: `/filament.svg?photo=${index}`}));
    const folder = {...base, id: 'archive', name: 'Archive', kind: 'folder', mediaType: null, mimeType: null, size: null, versionId: null, thumbnailUrl: null};
    const transport = {
      supportsBrowseControls: true,
      async workspaces() {return {items: [workspace], canCreate: false};},
      async workspace() {return workspace;},
      async browse(id, query) {
        window.headerRequests.push({workspace: id, query});
        return {items: query.parent ? files.slice(0, 5) : [folder, ...files], nextCursor: null, previousCursor: null, hasMore: false, canWrite: true};
      },
      async neighbors() {return {items: [], nextCursor: null, previousCursor: null, hasMore: false, canWrite: true};},
      async details(_workspace, id) {return {...files.find(file => file.id === id), parentId: null, metadata: {}, createdAt: base.updatedAt};},
      async status() {return {items: []};},
      async createFolder() {throw new Error('Not exercised by header tests');},
      async createNote() {throw new Error('Not exercised by header tests');},
      async upload() {throw new Error('Not exercised by header tests');},
      contentUrl(_workspace, id) {throw new Error(`Header must not request original content: ${id}`);},
    };
    const mount = (host, id, theme) => mountMediaStudio(host, {
      transport, theme,
      ...(user ? {user: {name: 'Alex Editor', email: 'alex@example.test', avatarUrl: '/filament.svg'}} : {}),
      onThemeChange(value) {window.headerThemeChanges.push({id, theme: value});},
    });
    window.headerInstances = [mount(document.querySelector('#studio'), 'first', 'light')];
    if (twoInstances) {
      document.querySelector('#studio').style.height = '450px';
      const second = document.createElement('div'); second.id = 'second'; second.style.height = '450px'; document.body.append(second);
      window.headerInstances.push(mount(second, 'second', 'dark'));
    }
  }, {twoInstances, user});
  await expect(page.locator('#studio .fms-title-row h1')).toHaveText('All files');
  return {page, errors, studio: page.locator('#studio')};
}

test('top search uses HeroUI and retains focus and input identity across browse updates', async () => {
  const {page, errors, studio} = await preview();
  try {
    const header = studio.locator('.fms-header');
    const field = header.locator('[data-slot="search-field"]');
    await expect(field).toHaveCount(1);
    const searchIcon = field.locator('[data-slot="search-field-search-icon"]');
    await expect(searchIcon).toHaveCount(1);
    const iconBox = await searchIcon.boundingBox();
    assert.ok(iconBox.width >= 16 && iconBox.width <= 24 && iconBox.height >= 16 && iconBox.height <= 24, JSON.stringify(iconBox));
    if (process.env.FMS_HEADER_SCREENSHOTS) await page.screenshot({path: '/tmp/fms-fixture-header.png'});
    const search = field.locator('input');
    const handle = await search.elementHandle();
    await search.fill('campaign');
    await expect.poll(() => page.evaluate(() => window.headerRequests.at(-1)?.query.search)).toBe('campaign');
    await expect(search).toBeFocused();
    assert.equal(await search.evaluate((element, original) => element === original, handle), true);
    await expect(header.getByRole('button', {name: 'New', exact: true})).toHaveCount(0);
    const searchBox = await field.boundingBox();
    const accountBox = await header.getByRole('button', {name: 'Account menu', exact: true}).boundingBox();
    const headerBox=await header.boundingBox();
    assert.ok(Math.abs(searchBox.x-headerBox.x-12)<1);
    assert.ok(searchBox.x + searchBox.width <= accountBox.x);
    await search.fill('');
    await expect.poll(() => page.evaluate(() => window.headerRequests.at(-1)?.query.search)).toBe('');
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('folder trail uses real HeroUI breadcrumbs under the title and navigates back', async () => {
  const {page, errors, studio} = await preview();
  try {
    await expect(studio.locator('[data-slot="breadcrumbs"]')).toHaveCount(0);
    await studio.getByRole('button', {name: 'Folder: Archive', exact: true}).click();
    await expect(studio.locator('.fms-title-row h1')).toHaveText('Archive');
    const breadcrumbs = studio.locator('.fms-title-row [data-slot="breadcrumbs"]');
    await expect(breadcrumbs).toHaveCount(1);
    await expect(breadcrumbs.locator('[data-slot="breadcrumbs-item"]')).toHaveCount(2);
    const titleBox = await studio.locator('.fms-title-row h1').boundingBox();
    const breadcrumbBox = await breadcrumbs.boundingBox();
    assert.ok(breadcrumbBox.y >= titleBox.y + titleBox.height);
    await breadcrumbs.getByRole('link', {name: 'All files', exact: true}).click();
    await expect(studio.locator('.fms-title-row h1')).toHaveText('All files');
    await expect.poll(() => page.evaluate(() => window.headerRequests.at(-1)?.query.parent)).toBe(null);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('account and New menus expose configured identity and capability actions without invented account actions', async () => {
  const {page, errors, studio} = await preview();
  try {
    const account = studio.getByRole('button', {name: 'Account menu', exact: true});
    await expect(account.locator('.avatar')).toHaveCount(1);
    await account.click();
    await expect(studio.getByText('Alex Editor', {exact: true})).toBeVisible();
    await expect(studio.getByText('alex@example.test', {exact: true})).toBeVisible();
    const popover = studio.locator('[data-slot="dropdown-popover"]');
    await expect(popover).toHaveCSS('opacity', '1');
    const accountBox = await popover.boundingBox();
    assert.ok(accountBox.width >= 180 && accountBox.width <= 280, JSON.stringify(accountBox));
    assert.equal(await popover.evaluate(element => element.style.width), '');
    if (process.env.FMS_HEADER_SCREENSHOTS) {
      await studio.locator('[data-slot="dropdown-popover"]').evaluate(async element => {await Promise.all(element.getAnimations({subtree: true}).map(animation => animation.finished.catch(() => {})));});
      await page.screenshot({path: '/tmp/fms-fixture-menu.png'});
    }
    await expect(studio.getByRole('tab', {name: 'Light mode', exact: true})).toBeVisible();
    await expect(studio.getByRole('tab', {name: 'Dark mode', exact: true})).toBeVisible();
    await expect(studio.getByRole('menuitem', {name: 'Settings',exact:true})).toHaveCount(1);
    await expect(studio.getByRole('menuitem', {name: /log out|sign out/i})).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(studio.getByRole('menu')).toHaveCount(0);
    await studio.getByRole('button', {name: 'Create', exact: true}).click();
    await expect(studio.getByRole('menuitem', {name: 'Upload files', exact: true})).toHaveCount(0);
    await expect(studio.getByRole('menuitem', {name: 'New folder', exact: true})).toBeVisible();
    await expect(studio.getByRole('menuitem', {name: 'New note', exact: true})).toBeVisible();
    await expect(studio.getByRole('menuitem', {name: 'Upload folder', exact: true})).toHaveCount(0);
    await page.keyboard.press('Escape');
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('layout dropdown supports Large grid and List while keeping already visible thumbnail nodes', async () => {
  const {page, errors, studio} = await preview();
  try {
    const thumbnail = studio.locator('.fms-entry img').first();
    const handle = await thumbnail.elementHandle();
    const layout = studio.getByRole('button', {name: 'Layout', exact: true});
    await layout.click();
    await expect(studio.getByRole('menuitemradio', {name: 'Grid view', exact: true})).toHaveAttribute('aria-checked', 'true');
    await studio.getByRole('menuitemradio', {name: 'Large grid', exact: true}).click();
    await expect(studio.getByRole('menu')).toHaveCount(0);
    await expect(studio.locator('.fms-scroll')).toHaveClass(/is-large-grid/);
    assert.equal(await thumbnail.evaluate((element, original) => element === original, handle), true);
    await layout.click();
    await studio.getByRole('menuitemradio', {name: 'List view', exact: true}).click();
    await expect(studio.locator('.fms-scroll')).toHaveClass(/is-list/);
    await layout.click();
    await studio.getByRole('menuitemradio', {name: 'Grid view', exact: true}).click();
    await expect(studio.locator('.fms-scroll')).toHaveClass(/is-grid/);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('mobile header and menus stay within their available width', async () => {
  const {page, errors, studio} = await preview(390);
  try {
    const overflow = await studio.locator('.fms-main').evaluate(element => ({width: element.clientWidth, scroll: element.scrollWidth, children: [...element.children].map(child => ({class: child.className, width: child.clientWidth, scroll: child.scrollWidth, right: Math.round(child.getBoundingClientRect().right)}))}));
    assert.ok(overflow.scroll <= overflow.width + 1, JSON.stringify(overflow));
    const header = studio.locator('.fms-header');
    await expect(header.locator('input')).toBeVisible();
    if (process.env.FMS_HEADER_SCREENSHOTS) await page.screenshot({path: '/tmp/fms-fixture-mobile-header.png'});
    await header.getByRole('button', {name: 'Account menu', exact: true}).click();
    const menu = studio.getByRole('menu');
    await expect(menu).toBeVisible();
    const box = await menu.boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 391, JSON.stringify(box));
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);
    assert.deepEqual(errors, []);
  } finally {await page.close();}

});

test('account appearance changes remain isolated per Studio instance', async () => {
  const pair = await preview(1440, {twoInstances: true});
  try {
    const first = pair.page.locator('#studio');
    const second = pair.page.locator('#second');
    await expect(first.locator('.fms-root')).toHaveClass(/light/);
    await expect(second.locator('.fms-root')).toHaveClass(/dark/);
    await first.getByRole('button', {name: 'Account menu', exact: true}).click();
    await first.getByRole('tab', {name: 'Dark mode', exact: true}).click();
    await expect.poll(() => pair.page.evaluate(() => window.headerThemeChanges)).toEqual([{id: 'first', theme: 'dark'}]);
    await expect(first.locator('.fms-root')).toHaveClass(/dark/);
    await expect(second.locator('.fms-root')).toHaveClass(/dark/);
    assert.deepEqual(pair.errors, []);
  } finally {await pair.page.close();}
});


test('toolbar uses neutral HeroUI buttons and consistent two-pixel Hugeicons', async () => {
  const {page, errors, studio} = await preview();
  try {
    const foreground = await studio.locator('.fms-shell').evaluate(element => getComputedStyle(element).color);
    const controls = [
      studio.getByRole('button', {name: 'Filters', exact: true}),
      studio.getByRole('button', {name: 'Upload', exact: true}),
      studio.getByRole('button', {name: 'Layout', exact: true}),
    ];
    for (const control of controls) {
      await expect(control).toHaveCSS('color', foreground);
      const strokeWidths = await control.locator('svg').evaluateAll(icons => icons.map(icon => icon.getAttribute('stroke-width')));
      assert.ok(strokeWidths.length > 0);
      assert.ok(strokeWidths.every(width => width === '2'), JSON.stringify(strokeWidths));
    }
    await expect(studio.locator('[data-slot="search-field-search-icon"]')).toHaveAttribute('stroke-width', '2');
    const controlColor = await controls[1].evaluate(element => getComputedStyle(element).color);
    assert.notEqual(controlColor, 'rgb(8, 102, 255)');
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});
