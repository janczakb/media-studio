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

async function preview({width = 1440, reduced = false} = {}) {
  const page = await browser.newPage({viewport: {width, height: 900}, reducedMotion: reduced ? 'reduce' : 'no-preference'});
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(origin);
  await page.evaluate(async () => {
    const {mountMediaStudio} = await import('/studio.js');
    window.photoRequests = []; window.photoNeighbors = []; window.photoContent = []; window.photoStars = [];
    const workspace = {id: 'brand', name: 'Brand assets', role: 'owner'};
    const items = Array.from({length: 600}, (_, index) => ({id: `photo-${index}`, name: `Photo ${String(index).padStart(3, '0')}.svg`, kind: 'file', mediaType: index % 5 === 0 ? 'video' : 'image', mimeType: index % 5 === 0 ? 'video/mp4' : 'image/svg+xml', size: 1024, versionId: `v${index}`, updatedAt: '2026-11-07T00:00:00Z', createdAt: '2026-11-06T00:00:00Z', revision: 1, trashedAt: null, starred: index % 3 === 0, width: index % 2 ? 320 : 180, height: index % 2 ? 180 : 320, thumbnailUrl: `/filament.svg?thumb=${index}`, previewUrl: '/filament.svg?preview='+index, previewState: 'ready'}));
    window.photoInstance = mountMediaStudio(document.querySelector('#studio'), {transport: {
      supportsBrowseControls: true,
      async workspaces() {return {items: [workspace], canCreate: false};},
      async browse(workspace, query, cursor) {
        window.photoRequests.push({workspace, query, cursor});
        const scope = items.filter(item => query.photos?.kind === 'image' ? item.mediaType === 'image' : query.photos?.kind === 'video' ? item.mediaType === 'video' : query.photos?.kind === 'starred' ? item.starred : true);
        const offset = Number(cursor ?? 0), end = offset + 60;
        return {items: scope.slice(offset, end), nextCursor: end < scope.length ? String(end) : null, previousCursor: offset > 0 ? String(Math.max(0, offset - 60)) : null, hasMore: end < scope.length, canWrite: true};
      },
      async neighbors(workspace, id, query, direction) {window.photoNeighbors.push({workspace, id, query, direction}); return {items: [], nextCursor: null, previousCursor: null, hasMore: false, canWrite: true};},
      async details(_workspace, id) {const entry = items.find(item => item.id === id); return {...entry, parentId: null, metadata: {}, createdAt: entry.createdAt};},
      async star(workspace, id, starred) {window.photoStars.push({workspace, id, starred}); items.find(item => item.id === id).starred = starred; return {starred};},
      async status() {return {items: []};},
      async createFolder() {throw new Error('Not used');}, async upload() {throw new Error('Not used');},
      contentUrl(_workspace, id) {window.photoContent.push(id); return '/original/'+id;},
    }});
  });
  await expect(page.locator('.fms-title-row h1')).toHaveText('All files');
  if (width < 760) await page.getByRole('button', {name: 'Open navigation', exact: true}).click();
  await page.getByRole('navigation', {name: 'Library', exact: true}).getByRole('button', {name: 'Photos', exact: true}).click();
  await expect(page.locator('.fms-photos-scroll .fms-photo-card').first()).toBeVisible();
  await expect.poll(() => page.locator('.fms-photo-cell').first().evaluate(element => getComputedStyle(element).transform)).toBe('none');
  return {page, errors};
}
const currentQuery = page => page.evaluate(() => window.photoRequests.at(-1).query);
const zoom = page => page.getByRole('slider', {name: 'Thumbnail size', exact: true});
const zoomValue = page => zoom(page).inputValue().then(Number);

async function animationSettled(page) {
  await page.locator('.fms-photos-scroll').evaluate(async element => {await Promise.all(element.getAnimations({subtree: true}).map(animation => animation.finished.catch(() => {})));});
}

test('Photos uses real HeroUI media/timeline controls and preserves query scope in favorites and preview neighbors', async () => {
  const {page, errors} = await preview();
  try {
    const media = page.getByRole('tablist', {name: 'Photos', exact: true});
    const periods = page.getByRole('tablist', {name: 'Timeline scale', exact: true});
    await expect(periods.getByRole('tab', {name: 'Days', exact: true})).toHaveAttribute('aria-selected', 'true');
    await expect.poll(() => currentQuery(page)).toEqual({parent: null, view: 'photos', search: '', photos: {kind: 'all', period: 'days'}});
    await media.getByRole('tab', {name: 'Photos', exact: true}).click();
    await periods.getByRole('tab', {name: 'Months', exact: true}).click();
    await expect.poll(() => currentQuery(page)).toEqual({parent: null, view: 'photos', search: '', photos: {kind: 'image', period: 'months'}});
    const card = page.locator('.fms-photo-cell[data-photo-id="photo-1"]');
    await card.hover();
    await card.getByRole('button', {name: 'More actions: Photo 001.svg', exact: true}).click();
    await page.getByRole('menuitem', {name: 'Add to favorites', exact: true}).click();
    await expect.poll(() => page.evaluate(() => window.photoStars)).toEqual([{workspace: 'brand', id: 'photo-1', starred: true}]);
    await card.getByRole('button', {name: 'Preview: Photo 001.svg', exact: true}).click();
    await expect(page.getByRole('dialog', {name: 'Photo 001.svg', exact: true})).toBeVisible();
    await expect(page.locator('.fms-preview-actionbar').getByRole('button', {name: 'Download', exact: true})).toBeVisible();
    await expect(page.locator('.fms-preview-footer')).toHaveCount(0);
    await expect.poll(() => page.locator('.fms-preview-original.is-ready').count()).toBe(1);
    await expect.poll(() => page.evaluate(() => window.photoNeighbors[0]?.query)).toEqual({parent: null, view: 'photos', search: '', photos: {kind: 'image', period: 'months'}});
    assert.deepEqual(await page.evaluate(() => window.photoContent), []);
    await page.keyboard.press('Escape');
    await media.getByRole('tab', {name: 'Starred', exact: true}).click();
    await expect.poll(() => currentQuery(page)).toEqual({parent: null, view: 'photos', search: '', photos: {kind: 'starred', period: 'months'}});
    await expect(page.locator('[data-photo-id="photo-1"]')).toBeVisible();
    await media.getByRole('tab', {name: 'Videos', exact: true}).click();
    await periods.getByRole('tab', {name: 'Years', exact: true}).click();
    await expect.poll(() => currentQuery(page)).toEqual({parent: null, view: 'photos', search: '', photos: {kind: 'video', period: 'years'}});
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('zoom controls preserve image DOM, aspect choice and reduced-motion behavior without fetching originals', async () => {
  const {page, errors} = await preview({reduced: true});
  try {
    const image = page.locator('[data-photo-id="photo-1"] img');
    const handle = await image.elementHandle();
    const before = await zoomValue(page);
    await page.getByRole('button', {name: 'Zoom in', exact: true}).click();
    await expect.poll(() => zoomValue(page)).toBeGreaterThan(before);
    assert.equal(await image.evaluate((element, original) => element === original, handle), true);
    assert.equal(await page.locator('.fms-photos-scroll').evaluate(element => element.getAnimations({subtree: true}).length), 0);
    await page.getByRole('radio', {name: 'Original proportions', exact: true}).click();
    await expect(page.locator('[data-photo-id="photo-1"]')).toHaveClass(/is-original/);
    await expect(image).toHaveCSS('object-fit', 'contain');
    await page.getByRole('radio', {name: 'Square grid', exact: true}).click();
    await expect(image).toHaveCSS('object-fit', 'cover');
    await zoom(page).focus();
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => zoomValue(page)).toBeGreaterThan(before);
    assert.deepEqual(await page.evaluate(() => window.photoContent), []);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('ordinary wheel scrolls, Ctrl-wheel zooms, and Safari-style gesture and touch pinch stay viewport-scoped', async () => {
  const {page, errors} = await preview();
  try {
    const viewport = page.locator('.fms-photos-scroll');
    const box = await viewport.boundingBox();
    const initial = await zoomValue(page);
    await page.mouse.move(box.x + box.width/2, box.y + 100);
    await page.mouse.wheel(0, 250);
    await expect.poll(() => viewport.evaluate(element => element.scrollTop)).toBeGreaterThan(0);
    assert.equal(await zoomValue(page), initial);
    await viewport.evaluate(element => element.dispatchEvent(new WheelEvent('wheel', {ctrlKey: true, deltaY: -35, bubbles: true, cancelable: true, clientX: element.getBoundingClientRect().x+20, clientY: element.getBoundingClientRect().y+20})));
    await expect.poll(() => zoomValue(page)).toBeGreaterThan(initial);
    await animationSettled(page);
    const afterWheel = await zoomValue(page);
    await viewport.evaluate(element => {
      for (const [type, scale] of [['gesturestart', 1], ['gesturechange', 1.1], ['gestureend', 1.1]]) {
        const event = new Event(type, {bubbles: true, cancelable: true}); Object.defineProperty(event, 'scale', {value: scale}); element.dispatchEvent(event);
      }
    });
    await expect.poll(() => zoomValue(page)).toBeGreaterThan(afterWheel);
    await animationSettled(page);
    const afterGesture = await zoomValue(page);
    const touchAvailable = await viewport.evaluate(element => {
      if (typeof Touch !== 'function' || typeof TouchEvent !== 'function') return false;
      const rect = element.getBoundingClientRect();
      const touches = distance => [new Touch({identifier: 1, target: element, clientX: rect.x+50, clientY: rect.y+60}), new Touch({identifier: 2, target: element, clientX: rect.x+50+distance, clientY: rect.y+60})];
      element.dispatchEvent(new TouchEvent('touchstart', {touches: touches(100), bubbles: true, cancelable: true}));
      element.dispatchEvent(new TouchEvent('touchmove', {touches: touches(115), bubbles: true, cancelable: true}));
      element.dispatchEvent(new TouchEvent('touchend', {touches: [], bubbles: true, cancelable: true}));
      return true;
    });
    assert.equal(touchAvailable, true, 'Chromium fixture must expose TouchEvent construction');
    await expect.poll(() => zoomValue(page)).toBeGreaterThan(afterGesture);
    const beforeHeader = await zoomValue(page);
    await page.locator('.fms-header').evaluate(element => element.dispatchEvent(new WheelEvent('wheel', {ctrlKey: true, deltaY: -40, bubbles: true, cancelable: true})));
    assert.equal(await zoomValue(page), beforeHeader);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('photo pagination keeps a bounded virtual window and mobile controls do not overflow', async () => {
  const {page, errors} = await preview({width: 390});
  try {
    const viewport = page.locator('.fms-photos-scroll');
    for (let index = 0; index < 8; index++) {
      await viewport.evaluate(element => {element.scrollTop = element.scrollHeight;});
      await expect.poll(() => page.evaluate(() => window.photoRequests.filter(request => request.query.view === 'photos' && request.cursor !== null).length)).toBeGreaterThanOrEqual(index+1);
    }
    assert.ok(await page.locator('.fms-photo-cell').count() < 100);
    assert.equal(await page.locator('[data-photo-id="photo-0"]').count(), 0);
    assert.ok(await page.locator('.fms-photo-cell img').evaluateAll(images => images.every(image => image.getAttribute('src').includes('?thumb='))));
    assert.deepEqual(await page.evaluate(() => window.photoContent), []);
    const main = await page.locator('.fms-main').evaluate(element => ({width: element.clientWidth, scroll: element.scrollWidth}));
    assert.ok(main.scroll <= main.width+1, JSON.stringify(main));
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});


test('zoom retains the photo vertical anchor after a scrolled timeline reflow', async () => {
  const {page, errors} = await preview({reduced: true});
  try {
    const viewport = page.locator('.fms-photos-scroll');
    await viewport.evaluate(element => {element.scrollTop = 700;});
    await expect.poll(() => viewport.evaluate(element => element.scrollTop)).toBeGreaterThan(600);
    const anchor = await viewport.evaluate(element => {
      const view = element.getBoundingClientRect();
      const x = view.left + view.width/2, y = view.top + view.height/2;
      const cells = [...element.querySelectorAll('[data-photo-id]')];
      const cell = cells.filter(candidate => {const box = candidate.getBoundingClientRect(); return box.top >= view.top && box.bottom <= view.bottom;}).sort((a, b) => {const aa = a.getBoundingClientRect(), bb = b.getBoundingClientRect(); return Math.hypot(aa.left + aa.width/2 - x, aa.top + aa.height/2 - y) - Math.hypot(bb.left + bb.width/2 - x, bb.top + bb.height/2 - y);})[0];
      if (!cell) throw new Error('No complete photo in the viewport');
      const box = cell.getBoundingClientRect();
      return {id: cell.dataset.photoId, x: box.left + box.width/2, y: box.top + box.height/2};
    });
    const image = page.locator(`[data-photo-id="${anchor.id}"] img`);
    const handle = await image.elementHandle();
    const before = await zoomValue(page);
    await viewport.evaluate((element, anchor) => element.dispatchEvent(new WheelEvent('wheel', {ctrlKey: true, deltaY: -30, clientX: anchor.x, clientY: anchor.y, bubbles: true, cancelable: true})), anchor);
    await expect.poll(() => zoomValue(page)).toBeGreaterThan(before);
    await expect.poll(async () => {
      const box = await page.locator(`[data-photo-id="${anchor.id}"]`).boundingBox();
      return !!box && Math.abs(box.y + box.height/2 - anchor.y) <= 4;
    }).toBe(true);
    assert.equal(await image.evaluate((element, original) => element === original, handle), true);
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('a continuous wheel series and its reversal reuse one stage transform without reflowing photo tiles inside a level', async () => {
  const {page, errors} = await preview();
  try {
    const viewport = page.locator('.fms-photos-scroll');
    const initial = await zoomValue(page);
    const report = await viewport.evaluate(async element => {
      const stage = element.querySelector('.fms-photo-virtual');
      const title=element.querySelector('[data-photo-heading]');
      const titleBefore=title.getBoundingClientRect();
      let titleStable=true;
      const cells = [...stage.querySelectorAll('[data-photo-id]')];
      const images = cells.map(cell => cell.querySelector('img'));
      const styles = cells.map(cell => cell.getAttribute('style'));
      const columns = stage.style.getPropertyValue('--fms-columns');
      let childrenChanged = false;
      const mutations = new MutationObserver(records => {if (records.some(record => record.type === 'childList')) childrenChanged = true;});
      mutations.observe(stage, {childList: true, subtree: true});
      const box = element.getBoundingClientRect();
      const samples = [];
      for (const deltaY of [-0.15, -0.15, -0.15, -0.15, -0.15, -0.15, 0.15, 0.15, 0.15, 0.15, 0.15, 0.15]) {
        element.dispatchEvent(new WheelEvent('wheel', {ctrlKey: true, deltaY, clientX: box.x + box.width/2, clientY: box.y + box.height/2, bubbles: true, cancelable: true}));
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
        const titleAfter=title.getBoundingClientRect();
        titleStable&&=['x','y','width','height'].every(key=>Math.abs(titleAfter[key]-titleBefore[key])<.1);
        samples.push({transform: stage.style.transform, columns: stage.style.getPropertyValue('--fms-columns'), tileAnimations: cells.reduce((count, cell) => count + cell.getAnimations().length, 0)});
      }
      mutations.disconnect();
      return {samples, columns, childrenChanged, titleStable, stable: cells.every((cell, index) => cell.isConnected && cell.querySelector('img') === images[index] && cell.getAttribute('style') === styles[index])};
    });
    assert.equal(report.childrenChanged, false);
    assert.equal(report.titleStable,true,'Timeline heading geometry must stay fixed while photos scale');
    assert.equal(report.stable, true);
    assert.ok(report.samples.every(sample => sample.columns === report.columns));
    assert.ok(report.samples.every(sample => sample.tileAnimations === 0));
    assert.ok(report.samples.some(sample => sample.transform.startsWith('scale(')), 'The live series must visibly scale the stage');
    assert.ok(report.samples.every(sample => sample.transform === '' || sample.transform.startsWith('scale(')));
    assert.ok(Math.abs(await zoomValue(page) - initial) <= 1);
    const stage = page.locator('.fms-photo-virtual');
    await expect.poll(() => stage.evaluate(element => element.style.transform)).toBe('');
    await animationSettled(page);
    await viewport.evaluate(element => element.dispatchEvent(new WheelEvent('wheel', {ctrlKey: true, deltaY: 2, bubbles: true, cancelable: true})));
    await expect.poll(() => stage.evaluate(element => Number(element.style.transform.match(/scale\(([^)]+)\)/)?.[1]??1))).toBeLessThan(1);
    await expect.poll(() => stage.evaluate(element => element.style.transform)).toBe('');
    await animationSettled(page);
    const settled = await zoomValue(page);
    await page.waitForTimeout(180);
    assert.equal(await zoomValue(page), settled, 'The completed series must not apply a stale pending update');
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('multi-level pinch and reversal retain images, project adjacent density levels and settle without a blank scene', async () => {
  const {page, errors}=await preview();
  try {
    const report=await page.locator('.fms-photos-scroll').evaluate(async element=>{
      const box=element.getBoundingClientRect(), samples=[];
      const initialColumns=Number(element.querySelector('.fms-photo-virtual').style.getPropertyValue('--fms-columns'));
      const image=element.querySelector('[data-photo-id="photo-0"] img');
      for(let i=0;i<72;i++){
        const deltaY=i<36?-3:3;
        element.dispatchEvent(new WheelEvent('wheel',{ctrlKey:true,deltaY,clientX:box.x+120,clientY:box.y+120,bubbles:true,cancelable:true}));
        await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
        const tile=element.querySelector('[data-photo-id="photo-0"]'),stage=element.querySelector('.fms-photo-virtual');
        const rect=tile?.getBoundingClientRect();
        const visible=[...element.querySelectorAll('[data-photo-id]')].filter(node=>{const r=node.getBoundingClientRect();return r.bottom>box.top&&r.top<box.bottom&&r.right>box.left&&r.left<box.right;}).length;
        samples.push({i,width:rect?.width,columns:Number(stage.style.getPropertyValue('--fms-columns')),visible,sameImage:tile?.querySelector('img')===image});
      }
      return {samples,initialColumns,viewportWidth:box.width};
    });
    const inward=report.samples.slice(0,36),outward=report.samples.slice(36);
    assert.ok(new Set(report.samples.map(sample=>sample.columns)).size>=3, 'The gesture must cross several column boundaries');
    assert.ok(Math.min(...inward.map(sample=>sample.columns))<report.initialColumns);
    assert.ok(outward.at(-1).columns>Math.min(...inward.map(sample=>sample.columns)), 'Reversing the gesture must return toward denser rows');
    for(const sample of report.samples){
      assert.equal(sample.sameImage,true,`Image node replaced at frame ${sample.i}`);
      assert.ok(sample.visible>0,`Blank viewport at frame ${sample.i}`);
      assert.ok(Number.isFinite(sample.width)&&sample.width>30&&sample.width<report.viewportWidth*1.4,`Invalid projected width at frame ${sample.i}: ${sample.width}`);
      const previous=report.samples[sample.i-1];
      if(previous){
        assert.ok(Math.abs(sample.columns-previous.columns)<=1,`Skipped adjacent density at frame ${sample.i}`);
        assert.ok(Math.abs(sample.width-previous.width)/previous.width<.2,`Discontinuous width at frame ${sample.i}: ${previous.width} -> ${sample.width}`);
      }
    }
    await expect.poll(()=>page.locator('.fms-photo-virtual').evaluate(element=>element.style.transform)).toBe('');
    await expect.poll(()=>page.locator('.fms-photo-cell').first().evaluate(element=>getComputedStyle(element).transform)).toBe('none');
    const viewport=await page.locator('.fms-photos-scroll').boundingBox();
    const cell=await page.locator('[data-photo-id="photo-0"]').boundingBox();
    assert.ok(cell.width>0&&cell.x>=viewport.x-1&&cell.x+cell.width<=viewport.x+viewport.width+1);
    assert.ok(await page.locator('[data-photo-id="photo-0"] img').evaluate(image=>image.complete&&image.naturalWidth>0));
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('Safari gesture owns mixed Touch events until gestureend and ignores a competing wheel stream',async()=>{
  const {page,errors}=await preview();
  try{
    const viewport=page.locator('.fms-photos-scroll'),stage=page.locator('.fms-photo-virtual');
    const initial=await zoomValue(page);
    await viewport.evaluate(element=>{
      const box=element.getBoundingClientRect();
      const touches=[new Touch({identifier:1,target:element,clientX:box.x+80,clientY:box.y+80}),new Touch({identifier:2,target:element,clientX:box.x+180,clientY:box.y+80})];
      element.dispatchEvent(new TouchEvent('touchstart',{touches,bubbles:true,cancelable:true}));
      for(const [type,scale]of[['gesturestart',1],['gesturechange',1.04]]){const event=new Event(type,{bubbles:true,cancelable:true});Object.defineProperties(event,{scale:{value:scale},clientX:{value:box.x+120},clientY:{value:box.y+100}});element.dispatchEvent(event);}
      element.dispatchEvent(new TouchEvent('touchend',{touches:[],bubbles:true,cancelable:true}));
    });
    const scale=()=>stage.evaluate(element=>Number(element.style.transform.match(/scale\(([^)]+)\)/)?.[1]??1));
    await expect.poll(scale).toBeGreaterThan(1.02);
    await page.waitForTimeout(220);
    assert.ok(await scale()>1.02,'Touch end or wheel idle must not settle Safari pinch');
    assert.equal(await zoomValue(page),initial,'Subthreshold explicit pinch must not commit a density level');
    await viewport.evaluate(element=>element.dispatchEvent(new WheelEvent('wheel',{ctrlKey:true,deltaY:-100,bubbles:true,cancelable:true})));
    await page.waitForTimeout(50);
    assert.ok(await scale()<1.06,'Competing wheel input must not change Safari-owned scale');
    await viewport.evaluate(element=>{const event=new Event('gesturechange',{bubbles:true,cancelable:true});Object.defineProperty(event,'scale',{value:1.1});element.dispatchEvent(event);});
    await expect.poll(scale).toBeGreaterThan(1.07);
    await viewport.evaluate(element=>element.dispatchEvent(new Event('gestureend',{bubbles:true,cancelable:true})));
    await expect.poll(()=>stage.evaluate(element=>element.style.transform)).toBe('');
    await expect.poll(()=>page.locator('.fms-photo-cell').first().evaluate(element=>getComputedStyle(element).transform)).toBe('none');
    await expect.poll(()=>zoomValue(page)).toBeGreaterThan(initial);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});
