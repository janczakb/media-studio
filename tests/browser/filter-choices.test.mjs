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

async function preview(width = 1440) {
  const page = await browser.newPage({viewport: {width, height: 900}});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(origin);
  await page.evaluate(async () => {
    const {mountMediaStudio} = await import('/studio.js');
    const workspace = {id: 'brand', name: 'Brand assets', role: 'owner'};
    window.choiceRequests = []; window.browseRequests = []; window.abortedChoices = []; window.pendingChoiceAborts = []; window.holdChoices = false;
    const transport = {
      supportsBrowseControls: true,
      async workspaces() {return {items: [workspace], canCreate: false};},
      async browse(_workspace, query) {window.browseRequests.push(query); return {items: [], nextCursor: null, previousCursor: null, hasMore: false, canWrite: true};},
      async browseChoices(workspace, kind, options, signal) {
        const key = JSON.stringify({workspace, kind, options});
        window.choiceRequests.push({workspace, kind, options});
        signal.addEventListener('abort', () => window.abortedChoices.push(key), {once: true});
        if (window.holdChoices) await new Promise((resolve, reject) => signal.addEventListener('abort', () => {window.pendingChoiceAborts.push(key); reject(new DOMException('Aborted', 'AbortError'));}, {once: true}));
        const offset = Number(options.cursor ?? 0);
        if (kind === 'tagLocales') return {items: [{id: 'en', label: 'English'}, {id: 'pl', label: 'Polish'}], nextCursor: null, hasMore: false};
        if (kind === 'tags') return {items: [{id: 'campaign', label: options.locale === 'pl' ? 'Kampania' : 'Campaign'}, {id: 'featured', label: 'Featured'}], nextCursor: null, hasMore: false};
        const items = options.search ? [{id: 'webp', label: 'WEBP'}] : Array.from({length: 60}, (_, index) => ({id: `ext-${offset + index}`, label: `EXT ${String(offset + index).padStart(3, '0')}`}));
        return {items, nextCursor: options.search ? null : String(offset + 60), hasMore: !options.search};
      },
      async createFolder() {throw new Error('Not used');}, async upload() {throw new Error('Not used');}, contentUrl() {throw new Error('Not used');},
    };
    window.instance = mountMediaStudio(document.querySelector('#studio'), {transport});
  });
  await expect(page.locator('.fms-title-row h1')).toHaveText('All files');
  return {page, errors};
}

const filters = page => page.evaluate(() => window.browseRequests.at(-1).filters);

test('Type supports multiple selections and keeps the same dropdown open through browse changes', async () => {
  const {page, errors} = await preview();
  try {
    await page.getByRole('button', {name: 'Type', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Type', exact: true});
    const handle = await dialog.elementHandle();
    await dialog.getByRole('option', {name: 'Images', exact: true}).click();
    await expect.poll(() => filters(page)).toEqual({types: ['image']});
    await expect(dialog).toBeVisible();
    assert.equal(await dialog.evaluate((element, original) => element === original, handle), true);
    await dialog.getByRole('option', {name: 'Videos', exact: true}).click();
    await expect.poll(() => filters(page)).toEqual({types: ['image', 'video']});
    await expect(dialog.getByRole('option', {name: 'Images', exact: true})).toHaveAttribute('aria-selected', 'true');
    await expect(dialog.getByRole('option', {name: 'Videos', exact: true})).toHaveAttribute('aria-selected', 'true');
    await dialog.getByRole('button', {name: 'Clear selection', exact: true}).click();
    await expect.poll(() => filters(page)).toBe(undefined);
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('Extension virtualizes rows, loads cursor pages on scroll and aborts pending work on close', async () => {
  const {page, errors} = await preview();
  try {
    assert.deepEqual(await page.evaluate(() => window.choiceRequests), []);
    await page.getByRole('button', {name: 'Extension', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Extension', exact: true});
    const list=dialog.getByRole('listbox',{name:'Extension',exact:true});
    await expect(dialog.getByRole('option',{name:'EXT 000',exact:true})).toBeVisible();
    assert.ok(await dialog.getByRole('option').count()<60,'only the visible extension rows mount');
    await expect(dialog.getByRole('button',{name:'Load more',exact:true})).toHaveCount(0);
    await dialog.getByRole('option',{name:'EXT 000',exact:true}).click();
    for(const cursor of ['60','120','180','240']){
      await list.evaluate(el=>{el.scrollTop=el.scrollHeight;});
      await expect.poll(()=>page.evaluate(cursor=>window.choiceRequests.some(r=>r.options.cursor===cursor),cursor)).toBe(true);
      await expect.poll(()=>list.evaluate(el=>el.scrollHeight)).toBeGreaterThan((Number(cursor)+40)*32);
      assert.ok(await dialog.getByRole('option').count()<60,'DOM remains bounded as cached pages grow');
    }
    await dialog.getByRole('searchbox').fill('web');
    await expect(dialog.getByRole('option',{name:'WEBP',exact:true})).toBeVisible();
    await dialog.getByRole('option',{name:'WEBP',exact:true}).click();
    await expect.poll(()=>filters(page)).toEqual({extensions:['ext-0','webp']});
    await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0);
    await page.getByRole('button',{name:'Extension',exact:true}).click();
    await expect(dialog.getByRole('option',{name:'EXT 000',exact:true})).toBeVisible();
    await expect(dialog.getByRole('option',{name:'EXT 000',exact:true})).toHaveAttribute('aria-selected','true');
    await expect.poll(()=>list.evaluate(el=>el.scrollHeight)).toBeGreaterThan(1900);
    const pendingCursor='60';
    const requestsBefore=await page.evaluate(()=>{window.holdChoices=true;return window.choiceRequests.length;});
    await list.evaluate(el=>{el.scrollTop=el.scrollHeight;});
    await expect.poll(()=>page.evaluate(({cursor,count})=>window.choiceRequests.slice(count).some(r=>r.options.cursor===cursor),{cursor:pendingCursor,count:requestsBefore})).toBe(true);
    await dialog.getByRole('searchbox').press('Escape');
    await expect.poll(()=>page.evaluate(cursor=>window.pendingChoiceAborts.some(key=>JSON.parse(key).options.cursor===cursor),pendingCursor)).toBe(true);
    await expect(dialog).toHaveCount(0);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('Tags match-all and tag language share the chosen locale without losing active filters', async () => {
  const {page, errors} = await preview();
  try {
    await page.getByRole('button', {name: 'Tags', exact: true}).click();
    const tags = page.getByRole('dialog', {name: 'Tags', exact: true});
    await tags.getByRole('option', {name: 'Campaign', exact: true}).click();
    await tags.getByRole('option', {name: 'Featured', exact: true}).click();
    await tags.getByText('Match all selected tags', {exact: true}).click();
    await expect(tags.getByRole('checkbox', {name: 'Match all selected tags', exact: true})).toBeChecked();
    await expect.poll(() => filters(page)).toEqual({tagIds: ['campaign', 'featured'], tagMatch: 'all'});
    await page.keyboard.press('Escape');
    await page.getByRole('button', {name: 'Tag language', exact: true}).click();
    const locales = page.getByRole('dialog', {name: 'Tag language', exact: true});
    await locales.getByRole('option', {name: 'Polish', exact: true}).click();
    await expect.poll(() => filters(page)).toEqual({tagIds: ['campaign', 'featured'], tagMatch: 'all', tagLocale: 'pl'});
    await page.keyboard.press('Escape');
    await page.getByRole('button', {name: 'Tags', exact: true}).click();
    await expect(tags.getByRole('option', {name: 'Kampania', exact: true})).toBeVisible();
    await expect.poll(() => page.evaluate(() => window.choiceRequests.at(-1))).toEqual({workspace: 'brand', kind: 'tags', options: {locale: 'pl'}});
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('mobile choice menus stay inside the viewport and respect the selection bound', async () => {
  const {page, errors} = await preview(390);
  try {
    await page.getByRole('button', {name: 'Filters', exact: true}).click();
    await page.getByRole('button', {name: 'Extension', exact: true}).click();
    const dialog = page.getByRole('dialog', {name: 'Extension', exact: true});
    await expect(dialog.getByRole('option',{name:'EXT 000',exact:true})).toBeVisible();
    const box = await dialog.boundingBox();
    assert.ok(box.x >= 0 && box.x + box.width <= 390, JSON.stringify(box));
    const insets=await dialog.getByRole('option',{name:'EXT 000',exact:true}).evaluate(row=>{
      const list=row.closest('[role=listbox]');
      const surface=list.closest('[role=dialog]').getBoundingClientRect();
      const bounds=row.getBoundingClientRect();
      return {left:bounds.left-surface.left,right:surface.right-bounds.right,listEdge:surface.right-list.getBoundingClientRect().right};
    });
    assert.ok(insets.left>=5&&insets.right>=5&&Math.abs(insets.listEdge)<1,JSON.stringify(insets));
    await dialog.getByRole('button', {name: 'Select shown', exact: true}).click();
    await expect.poll(async () => (await filters(page))?.extensions.length).toBe(32);
    await expect(dialog.getByRole('option',{name:'EXT 000',exact:true})).toHaveAttribute('aria-selected','true');
    const list=dialog.getByRole('listbox');
    await list.evaluate(el=>{el.scrollTop=32*36;});
    await expect(dialog.getByRole('option',{name:'EXT 034',exact:true})).toHaveAttribute('aria-disabled','true');
    const main = await page.locator('.fms-main').evaluate(element => ({width: element.clientWidth, scroll: element.scrollWidth}));
    assert.ok(main.scroll <= main.width + 1, JSON.stringify(main));
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});
