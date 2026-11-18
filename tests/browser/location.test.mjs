import {localeAssets} from './locale-assets.mjs';
import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium, expect} from '@playwright/test';

let browser, server, origin;
before(async () => {
  const files = new Map(await Promise.all([
    ['studio.js', 'text/javascript'], ['studio.css', 'text/css'], ['filament.svg', 'image/svg+xml'], ['studio-mark.svg', 'image/svg+xml'],
  ].map(async ([name, type]) => ['/' + name, {type, body: await readFile(new URL('../../packages/react/dist/' + name, import.meta.url))}])));
  for(const [name,value]of await localeAssets({raw:false}))files.set(name,value);

  const html = `<!doctype html><html><body style="margin:0"><div id="studio" style="height:100vh"></div>
<script type="module">
import {mountMediaStudio} from '/studio.js';
const workspace = {id:'brand', name:'Brand library', role:'owner'};
const base = {updatedAt:'2026-11-07T00:00:00Z',createdAt:'2026-11-07T00:00:00Z',revision:1,trashedAt:null,metadata:{}};
const files = [
 {...base,id:'archive',name:'Archive',kind:'folder',mediaType:null,mimeType:null,size:null,versionId:null,parentId:null},
 {...base,id:'drafts',name:'Drafts',kind:'folder',mediaType:null,mimeType:null,size:null,versionId:null,parentId:'archive'},
 {...base,id:'cover',name:'Cover.svg',kind:'file',mediaType:'image',mimeType:'image/svg+xml',size:512,versionId:'v1',parentId:'drafts',previewUrl:'/filament.svg'},
];
const transport = {
 async workspaces(){return {items:[workspace],canCreate:false};},
 async browse(_,query){return {items:files.filter(file=>query.view==='photos'?file.kind==='file':file.parentId===query.parent),hasMore:false,nextCursor:null,previousCursor:null,canWrite:false};},
 async details(_,id){const item=files.find(file=>file.id===id);if(!item)throw new Error('Missing');return item;},
 async neighbors(){return {items:[],hasMore:false};},
 async createFolder(){throw new Error('unused');},async upload(){throw new Error('unused');},
 contentUrl(){return '/filament.svg';}
};
mountMediaStudio(document.querySelector('#studio'), {syncUrl:true,transport});
</script></body></html>`;
  server = createServer((request, response) => {
    const file = files.get(new URL(request.url, 'http://localhost').pathname);
    response.writeHead(200, {'Content-Type': file?.type ?? 'text/html'});
    response.end(file?.body ?? html);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = 'http://127.0.0.1:' + server.address().port;
  browser = await chromium.launch();
});
after(async () => {await browser?.close(); await new Promise(resolve => server?.close(resolve));});

test('nested folder and fullscreen file survive reload and preserve host query state', async () => {
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await page.goto(origin + '/?campaign=spring');
    await page.getByRole('button', {name:'Folder: Archive',exact:true}).click();
    await page.getByRole('button', {name:'Folder: Drafts',exact:true}).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('fmsFolder')).toBe('drafts');
    await page.reload();
    await expect(page.locator('.fms-breadcrumbs')).toContainText('Archive');
    await expect(page.locator('.fms-breadcrumbs')).toContainText('Drafts');
    await page.getByRole('button', {name:'Preview: Cover.svg',exact:true}).click();
    await expect.poll(() => new URL(page.url()).searchParams.get('fmsFile')).toBe('cover');
    await page.reload();
    const preview = page.getByRole('dialog', {name:'Cover.svg',exact:true});
    await expect(preview).toBeVisible();
    assert.equal(new URL(page.url()).searchParams.get('campaign'), 'spring');
    await preview.getByRole('button', {name:'Close',exact:true}).click();
    await expect(preview).toHaveCount(0);
    assert.equal(new URL(page.url()).searchParams.get('fmsFile'), null);
    assert.equal(new URL(page.url()).searchParams.get('fmsFolder'), 'drafts');
    assert.deepEqual(errors, []);
  } finally {await page.close();}
});

test('Photos media and timeline choices restore through reload and browser history', async () => {
  const page = await browser.newPage();
  try {
    await page.goto(origin);
    await page.getByRole('button', {name:'Photos',exact:true}).click();
    await page.getByRole('tab', {name:'Videos',exact:true}).click();
    await page.getByRole('tab', {name:'Years',exact:true}).click();
    await page.reload();
    await expect(page.getByRole('tab', {name:'Videos',exact:true})).toHaveAttribute('aria-selected','true');
    await expect(page.getByRole('tab', {name:'Years',exact:true})).toHaveAttribute('aria-selected','true');
    await page.getByRole('navigation', {name:'Library',exact:true}).getByRole('button',{name:'All files',exact:true}).click();
    await page.goBack();
    await expect(page.getByRole('tab', {name:'Videos',exact:true})).toHaveAttribute('aria-selected','true');
    await page.goForward();
    await expect(page.getByRole('button',{name:'Folder: Archive',exact:true})).toBeVisible();
  } finally {await page.close();}
});
