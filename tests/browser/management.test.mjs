import {localeAssets} from './locale-assets.mjs';
import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readdir,readFile} from 'node:fs/promises';
import {chromium, expect} from '@playwright/test';

let browser, server, origin;
before(async () => {
  const assets = new Map(await Promise.all([
    ['studio.js', 'text/javascript'], ['studio.css', 'text/css'], ['filament.svg', 'image/svg+xml'], ['studio-mark.svg', 'image/svg+xml'], ['share-qr.js', 'text/javascript'],
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

const now = '2026-11-07T12:00:00Z';
const asset = {id:'image', name:'Campaign.png', kind:'file', mediaType:'image', mimeType:'image/png', size:1024, versionId:'v1', updatedAt:now, revision:1, trashedAt:null};
const baseShare = {id:'share-id',title:'Campaign.png',kind:'file',allowDownload:true,passwordProtected:true,expiresAt:null,revokedAt:null,createdAt:now,revision:1,entryCount:1,hasRecoverableUrl:true,ownedByMe:true,canEdit:true};
const pageOf = (items, nextCursor = null, previousCursor = null) => ({items,nextCursor,previousCursor,hasMore:nextCursor!==null});

async function open(width=1440,options={}) {
  const page = await browser.newPage({viewport:{width,height:900},permissions:['clipboard-read','clipboard-write']});
  page.setDefaultTimeout(6500);
  const errors = [];
  const requests = [];
  const shares = [];
  let restored = false;
  const notification = {id:'note',name:'Campaign.png',event:'uploaded',entryId:'image',createdAt:now,readAt:null};
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/api/**', async route => {
    const request = route.request();
    const url = new URL(request.url());
    const method = request.method();
    const path = url.pathname.replace('/api','');
    const body = request.postDataJSON();
    const query = Object.fromEntries(url.searchParams);
    requests.push({path,method,body,query,csrf:request.headers()['x-csrf-token']});
    const respond = (json, status=200) => route.fulfill({json,status});
    const workspace = {id:'one',name:'Campaign workspace',role:'owner'};
    if(path==='/workspaces') return respond({items:[workspace],canCreate:false});
    if(path==='/workspaces/one') return respond(workspace);
    if(path.endsWith('/entries')) return respond({...pageOf([asset]),canWrite:true});
    if(path.endsWith('/shares')) {
      if(method==='POST') {
        const record = {...baseShare,title:body.title,allowDownload:body.allowDownload,allowArchive:body.allowArchive,passwordProtected:!!body.password,expiresAt:body.expiresAt};
        shares.push(record);
        return respond({...record,url:`${origin}/public/share-test`},201);
      }
      const records = shares.filter(item=>(query.status==='revoked')===Boolean(item.revokedAt) && query.status!=='expired');
      return respond({...pageOf(records),canManage:true});
    }
    if(path.endsWith('/shares/share-id/link'))return respond({url:`${origin}/public/existing-link`});
    if(path.endsWith('/shares/share-id')&&method==='PATCH') {
      if(shares[0]?.revision!==body.revision)return respond({message:'Conflict'},409);
      Object.assign(shares[0],{title:body.title??shares[0].title,revokedAt:body.revoked?now:null,revision:body.revision+1});
      return respond(shares[0]);
    }
    if(path.endsWith('/trash-report')) {
      const entry = {...asset,name:'Retired.png',id:'retired',trashedAt:now,occurredAt:now,actor:null,canRestore:!restored};
      const items = query.tab==='restorations' ? restored?[{...entry,activityId:'activity',trashedAt:null,available:true}]:[] : restored||query.tab==='folders'?[]:[entry];
      return respond({...pageOf(items),retention:{enabled:true,days:30}});
    }
    if(path.endsWith('/entries/retired/restore')) {restored=true;return respond({...asset,id:'retired',name:'Retired.png'});}
    if(options.notifications&&path.endsWith('/notifications')){
      const offset=Number(query.cursor??0),total=options.notifications;
      const items=Array.from({length:Math.min(60,total-offset)},(_,i)=>({...notification,id:'note-'+(offset+i),name:'Notification '+(offset+i),readAt:now}));
      return respond({...pageOf(items,offset+60<total?String(offset+60):null,offset>0?String(Math.max(0,offset-60)):null),unreadCount:0});
    }
    if(path.endsWith('/notifications')) return respond({...pageOf(query.unread==='1'&&notification.readAt?[]:[{...notification,actor:{name:'Actual author',avatarUrl:null}}]),unreadCount:notification.readAt?0:10});
    if(path.endsWith('/notifications/read-visible')){notification.readAt=now;return respond({updated:1,unreadCount:0});}
    if(path.endsWith('/notifications/note')) {notification.readAt=body.read?now:null;return respond({id:notification.id,readAt:notification.readAt});}
    if(path.endsWith('/notifications/read-all')) {notification.readAt=now;return respond({updated:1});}
    if(path.endsWith('/tags')) {
      if(query.search) return respond(pageOf([{id:'launch',label:'Launch',locale:'en'}]));
      if(query.cursor) return respond(pageOf([{id:'second',label:'Second page'}],null,'previous'));
      return respond(pageOf(Array.from({length:60},(_,i)=>({id:`tag-${i}`,label:`Tag ${i}`})),'next'));
    }
    return respond({message:`Unexpected endpoint ${path}`},404);
  });
  await page.clock.setFixedTime(new Date(now));
  await page.goto(origin);
  await page.evaluate(async () => {
    const {mountMediaStudio,createHttpTransport} = await import('/studio.js');
    window.instance = mountMediaStudio(document.querySelector('#studio'),{transport:createHttpTransport({baseUrl:'/api',csrfToken:()=> 'test-csrf'})});
  });
  await expect(page.getByRole('heading',{name:'All files',exact:true})).toBeVisible();
  return {page,errors,requests};
}
const nav = (page,label) => page.locator(label==='Shares'||label==='Notifications'?'.fms-rail':'.fms-context-nav').getByRole('button',{name:label,exact:true}).click();

for(const width of [1440,390]) test(`Shares and Notifications hide the sidebar at ${width}px and Library restores it`,async()=>{
  const {page,errors}=await open(width);
  try {
    const rail=page.locator('.fms-rail');
    await rail.getByRole('button',{name:'Shares',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Shared links'})).toBeVisible();
    await expect(page.locator('.fms-sidebar')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Open navigation'})).toHaveCount(0);
    const railBox=await rail.boundingBox(), mainBox=await page.locator('.fms-main').boundingBox();
    assert.ok(Math.abs(mainBox.x-(railBox.x+railBox.width))<1);
    await expect(rail.getByRole('button',{name:'Shares',exact:true})).toHaveAttribute('aria-current','page');
    await rail.getByRole('button',{name:'Notifications',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Notifications',exact:true})).toBeVisible();
    await expect(page.locator('.fms-sidebar')).toHaveCount(0);
    await expect(rail.getByRole('button',{name:'Notifications',exact:true})).toHaveAttribute('aria-current','page');
    await rail.getByRole('button',{name:'Library',exact:true}).click();
    await expect(page.getByRole('heading',{name:'All files',exact:true})).toBeVisible();
    await expect(page.locator('.fms-sidebar')).toHaveCount(1);
    const settings=rail.getByRole('button',{name:'Settings',exact:true});
    const notifications=rail.getByRole('button',{name:'Notifications',exact:true});
    assert.ok((await settings.boundingBox()).y>(await notifications.boundingBox()).y);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('creates a protected share from a file, copies the one-time URL and revokes it from Shares',async()=>{
  const {page,errors,requests}=await open();
  try {
    await page.getByRole('button',{name:/More actions: Campaign.png/}).click();
    await page.getByRole('menuitem',{name:'Share',exact:true}).click();
    const hub=page.getByRole('dialog',{name:'Share Campaign.png'});
    await hub.getByRole('button',{name:/Link access/}).click();
    await page.getByRole('option',{name:'Can view',exact:true}).click();
    const dialog=page.getByRole('dialog',{name:'Create share link'});
    await expect(dialog.getByRole('textbox',{name:'Link name'})).toHaveValue('Campaign.png');
    await dialog.locator('[data-slot="switch-content"]').filter({hasText:'Allow downloads'}).click();
    await dialog.locator('[data-slot="switch-content"]').filter({hasText:'Allow downloading the full collection'}).click();
    await dialog.locator('[data-slot="switch-content"]').filter({hasText:'Require a password'}).click();
    const password=dialog.getByLabel('Password',{exact:true});await password.fill('a-long-password');
    await expect(password).toHaveAttribute('type','password');await dialog.getByRole('button',{name:'Show password',exact:true}).click();await expect(password).toHaveAttribute('type','text');await expect(password).toHaveValue('a-long-password');await dialog.getByRole('button',{name:'Hide password',exact:true}).click();await expect(password).toHaveAttribute('type','password');
    const fieldSizes=await dialog.evaluate(el=>({name:el.querySelector('input[maxlength="160"]').offsetHeight,password:el.querySelector('.fms-share-password').offsetHeight}));assert.equal(fieldSizes.name,42);assert.equal(fieldSizes.password,42);
    await dialog.locator('[data-slot="switch-content"]').filter({hasText:'Set an expiration date'}).click();
    await dialog.locator('[data-slot="date-picker-trigger"]').click();
    await dialog.getByRole('button',{name:'Sunday, November 8, 2026',exact:true}).click();
    await dialog.getByRole('button',{name:'Create share link',exact:true}).click();
    await expect(page.getByRole('textbox',{name:'Share link',exact:true})).toHaveValue(`${origin}/public/share-test`);
    await page.getByRole('button',{name:'Show QR code'}).click();
    await expect(page.getByRole('img',{name:'QR code for the shared link'})).toHaveAttribute('src', /^data:image\/png;base64,/);
    await page.getByRole('button',{name:'Hide QR code'}).click();
    await expect(page.getByRole('img',{name:'QR code for the shared link'})).toHaveCount(0);
    const receipt=page.getByRole('dialog',{name:'Share link',exact:true});
    await receipt.getByRole('button',{name:'Copy link',exact:true}).click();
    await expect(receipt.getByRole('button',{name:'Copied',exact:true})).toBeVisible();
    assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),`${origin}/public/share-test`);
    await page.getByRole('button',{name:'Done',exact:true}).click();
    await hub.getByRole('button',{name:'Close',exact:true}).click();
    const created=requests.find(request=>request.path.endsWith('/shares')&&request.method==='POST');
    assert.equal(new Date(created.body.expiresAt).getHours(),23);
    assert.deepEqual(created.body,{title:'Campaign.png',allowDownload:true,allowArchive:true,expiresAt:created.body.expiresAt,password:'a-long-password',collectAnalytics:false,recipientPermission:'view',entryIds:['image']});
    assert.equal(created.csrf,'test-csrf');
    await nav(page,'Shares');
    const mine=page.locator('.fms-shares-mine');const centers=await mine.evaluate(el=>{const a=el.querySelector('[data-slot=checkbox-control]').getBoundingClientRect(),b=el.querySelector('label,[data-slot=label]').getBoundingClientRect();return [a.y+a.height/2,b.y+b.height/2];});assert.ok(Math.abs(centers[0]-centers[1])<1);
    await page.getByRole('button',{name:'Manage link: Campaign.png'}).click();
    await expect(page.getByRole('textbox',{name:'URL',exact:true})).toHaveValue(`${origin}/public/existing-link`);
    const urlLayout=await page.locator('.fms-share-current-link').evaluate(el=>{const row=el.getBoundingClientRect(),field=el.querySelector('.textfield').getBoundingClientRect(),button=el.querySelector('button').getBoundingClientRect();return {gap:button.x-field.right,end:row.right-button.right,width:field.width,row:row.width};});assert.ok(urlLayout.gap<=12&&urlLayout.end<1&&urlLayout.width>urlLayout.row*.65,JSON.stringify(urlLayout));
    assert.ok(await page.locator('.fms-share-option>svg').count()>=3);
    const compact=await page.locator('.fms-share-editor').evaluate(el=>{const opts=[...el.querySelectorAll('.fms-share-option')];return {name:el.querySelector('input[maxlength="160"]').offsetHeight,url:el.querySelector('.fms-share-current-link input').offsetHeight,pitch:opts[1].getBoundingClientRect().top-opts[0].getBoundingClientRect().top};});assert.equal(compact.name,42);assert.equal(compact.url,42);assert.ok(compact.pitch<=42,JSON.stringify(compact));
    await page.getByRole('button',{name:'Copy link',exact:true}).click();
    assert.equal(await page.evaluate(()=>navigator.clipboard.readText()),`${origin}/public/existing-link`);
    await page.getByRole('textbox',{name:'Link name'}).fill('Campaign launch');
    await page.getByRole('button',{name:'Save changes',exact:true}).click();
    await expect.poll(()=>requests.filter(request=>request.method==='PATCH').length).toBe(1);
    await page.getByRole('button',{name:'Disable link',exact:true}).click();
    const confirm=page.getByRole('dialog',{name:'Revoke this shared link?'});
    await confirm.getByRole('button',{name:'Revoke access',exact:true}).click();
    await expect(page.getByText('No links in this view.')).toBeVisible();
    await page.getByRole('tab',{name:'Revoked'}).click();
    await expect(page.getByText('Campaign launch',{exact:true})).toBeVisible();
    assert.deepEqual(requests.filter(request=>request.method==='PATCH').map(request=>request.body),[
      {revision:1,title:'Campaign launch',allowDownload:true,allowArchive:true,collectAnalytics:false},
      {revision:2,revoked:true},
    ]);
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

for(const width of [390,1440])test(`Share tabs and Publish creation use HeroUI at ${width}px`,async()=>{
  const {page,errors,requests}=await open(width);
  try {
    await page.getByRole('button',{name:/More actions: Campaign.png/}).click();
    await page.getByRole('menuitem',{name:'Share',exact:true}).click();
    const hub=page.getByRole('dialog',{name:'Share Campaign.png'});
    const share=hub.getByRole('tabpanel',{name:'Share'});
    await expect(share.getByRole('textbox',{name:'Email, comma separated'})).toBeVisible();
    const layout=await hub.evaluate(dialog=>{
      const box=selector=>dialog.querySelector(selector).getBoundingClientRect();
      const tabs=box('[role=tablist]'),panel=box('[role=tabpanel]'),row=box('.fms-share-invite');
      const email=box('.fms-share-invite input'),access=box('.fms-share-invite [data-slot=select-trigger]'),invite=box('.fms-share-invite button[type=submit]');
      return {compactTabs:tabs.width<panel.width*.75,sameRow:Math.abs(email.top-invite.top)<6,ordered:email.right<=access.left&&access.right<=invite.left,usesWidth:Math.abs(row.right-invite.right)<2&&email.width>90,metrics:[row.right,invite.right,email.width]};
    });
    assert.ok(layout.compactTabs&&layout.sameRow&&layout.ordered&&layout.usesWidth,JSON.stringify(layout));
    await share.locator('.fms-share-invite [data-slot=select-trigger]').click();
    await page.getByRole('option',{name:'Can comment',exact:true}).click();
    await expect(share.locator('.fms-share-invite [data-slot=select-trigger]')).toHaveText('Can comment');
    await hub.getByRole('tab',{name:'Publish',exact:true}).click();
    const publish=hub.getByRole('tabpanel',{name:'Publish'});
    await expect(publish.getByRole('heading',{name:'Publish this collection to the web'})).toBeVisible();
    const fits=await publish.evaluate(el=>el.scrollWidth<=el.clientWidth);
    assert.equal(fits,true);
    await publish.getByRole('button',{name:'Create link',exact:true}).click();
    const editor=page.getByRole('dialog',{name:'Create publish link',exact:true});
    await expect(editor.getByRole('textbox',{name:'Link name'})).toHaveValue('Campaign.png');
    await expect(editor.getByText('Allow indexing by search engines',{exact:true})).toBeVisible();
    await editor.getByRole('button',{name:'Close',exact:true}).click();
    await expect(publish.getByRole('button',{name:'Create link',exact:true})).toBeVisible();
    assert.ok(!requests.some(request=>request.method==='POST'&&request.path.endsWith('/shares')));
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('trash restores with the current revision and shows its restoration history',async()=>{
  const {page,errors,requests}=await open();
  try {
    await nav(page,'Trash');
    await expect(page.getByRole('heading',{name:'Deleted files'})).toBeVisible();
    await expect(page.getByText('Deleted items become eligible for permanent cleanup after 30 days.')).toBeVisible();
    await page.getByRole('button',{name:'Restore: Retired.png'}).click();
    await page.getByRole('dialog',{name:'Restore this item?'}).getByRole('button',{name:'Restore',exact:true}).click();
    await expect(page.getByText('No deleted items match these filters.')).toBeVisible();
    await page.getByRole('tab',{name:'Restorations'}).click();
    await expect(page.getByText('Retired',{exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:/Restore: Retired/})).toHaveCount(0);
    assert.deepEqual(requests.find(request=>request.path.endsWith('/restore')).body,{revision:1});
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('notifications mark visible rows in one batch without replacing the list',async()=>{
 const {page,errors,requests}=await open();try{
  await expect(page.locator('.fms-notification-badge')).toHaveText('9+');
  await nav(page,'Notifications');
  await expect(page.getByRole('button',{name:/Campaign.png/})).toBeVisible();
  const row=page.getByRole('listitem').filter({hasText:'Campaign.png'});await row.evaluate(el=>window.originalNotification=el);
  await expect.poll(()=>requests.filter(r=>r.path.endsWith('/notifications/read-visible')).length).toBe(1);
  await expect(page.getByText('Actual author',{exact:true})).toBeVisible();
  await expect(page.locator('.fms-notification-badge')).toHaveCount(0);
  assert.equal(await row.evaluate(el=>el===window.originalNotification),true);
  await expect(page.getByRole('button',{name:/Mark as read|Mark as unread|Mark all as read/})).toHaveCount(0);
  await expect(page.locator('.fms-management-footer [data-slot=pagination]')).toBeVisible();
  assert.deepEqual(requests.find(r=>r.path.endsWith('/notifications/read-visible')).body,{ids:['note']});
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});

test('tag list virtualizes the page, drops the old cursor on search and opens the tagged library',async()=>{
  const {page,errors,requests}=await open();
  try {
    await nav(page,'Tags');
    await expect(page.getByText('Tag 0',{exact:true})).toBeVisible();
    assert.ok(await page.locator('.fms-management-row').count()<60);
    await page.setViewportSize({width:390,height:700});
    assert.equal(await page.locator('.fms-management').evaluate(element=>element.scrollWidth>element.clientWidth),false);
    await page.getByRole('button',{name:'Next page',exact:true}).click();
    await expect(page.getByText('Second page',{exact:true})).toBeVisible();
    await page.getByRole('searchbox',{name:'Search tags'}).fill('launch');
    await page.getByRole('button',{name:'Browse tag: Launch'}).click();
    await expect(page.getByRole('heading',{name:'Tagged files'})).toBeVisible();
    assert.deepEqual(requests.filter(request=>request.path.endsWith('/tags')).map(request=>[request.query.search,request.query.cursor]),[['',undefined],['','next'],['launch',undefined]]);
    assert.equal(requests.findLast(request=>request.path.endsWith('/entries')).query['filters[tagIds][0]'],'launch');
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});


for(const width of [1440,390])test(`notification list scrolls and cursor controls remain usable at ${width}px`,async()=>{
 const {page,errors,requests}=await open(width,{notifications:130});try{
  await nav(page,'Notifications');const list=page.locator('.fms-notifications .fms-management-list'),footer=page.locator('.fms-notifications .fms-management-footer');
  await expect(page.getByRole('button',{name:'File added Notification 0',exact:true})).toBeVisible();
  const geometry=await list.evaluate(el=>({height:el.clientHeight,total:el.scrollHeight}));assert.ok(geometry.height>100&&geometry.total>geometry.height,JSON.stringify(geometry));
  assert.ok(await list.getByRole('listitem').count()<25);
  await list.hover();await page.mouse.wheel(0,5000);await expect.poll(()=>list.evaluate(el=>el.scrollTop)).toBeGreaterThan(1000);
  await expect(page.getByRole('button',{name:'File added Notification 59',exact:true})).toBeVisible();
  const box=await footer.boundingBox();assert.ok(box.y+box.height<=900);
  await footer.getByRole('button',{name:'Next page',exact:true}).click();await expect(page.getByRole('button',{name:'File added Notification 60',exact:true})).toBeVisible();await expect(footer.getByRole('button',{name:'2',exact:true})).toBeVisible();
  await footer.getByRole('button',{name:'Next page',exact:true}).click();await expect(page.getByRole('button',{name:'File added Notification 120',exact:true})).toBeVisible();await expect(footer.getByRole('button',{name:'Next page',exact:true})).toBeDisabled();
  await footer.getByRole('button',{name:'Previous page',exact:true}).click();await expect(page.getByRole('button',{name:'File added Notification 60',exact:true})).toBeVisible();
  assert.ok(requests.some(r=>r.query.cursor==='60'));assert.ok(requests.some(r=>r.query.cursor==='120'));assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
