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
    const workspace = {id:'one',name:'Campaign workspace',role:'owner',permissions:{'members.view':true,'members.invite':true,'members.manage':true}};
    if(path.endsWith('/member-candidates')) return respond({items:[{id:'two',type:'user',name:'Alice Example',email:'alice@example.test',role:'editor',canRemove:true}]});
    if(path.endsWith('/members')) return respond(method==='POST'?{added:true}:pageOf(options.emptyMembers?[]:[{id:'owner',name:'Owner',email:'owner@example.test',role:'owner',canRemove:false}]));
    if(path.endsWith('/invitations')) return respond(method==='POST'?{sent:true}:{items:options.emptyInvitations?[]:[{id:'invite',email:'guest@example.test',role:'viewer',expires_at:'2030-01-01T00:00:00Z',accepted_at:null,revoked_at:null}],nextCursor:url.searchParams.has('after')?null:'next'});

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

for(const width of [1440,390])test(`Members modal and invitation table at ${width}px`,async()=>{
 const {page,errors,requests}=await open(width);
 try{
  await page.locator('.fms-rail').getByRole('button',{name:'Members',exact:true}).click();
  await expect(page.locator('.fms-members-table table')).toBeVisible();
  assert.ok(await page.getByRole('tablist',{name:'People & access'}).evaluate(el=>el.getBoundingClientRect().width<300));
  const avatar=page.locator('.fms-members-table .avatar__fallback').first();
  assert.equal(await avatar.evaluate(el=>getComputedStyle(el).backgroundColor),'rgb(222, 247, 223)');
  await page.getByRole('button',{name:'Add people',exact:true}).click();
  const dialog=page.getByRole('dialog');
  await expect(dialog.getByRole('tab',{name:'Existing users',exact:true})).toBeVisible();
  assert.equal(await dialog.evaluate(el=>getComputedStyle(el).overflow),'visible');
  const pickerHeight=await dialog.locator('.autocomplete__trigger').evaluate(el=>el.offsetHeight);
  await dialog.locator('.autocomplete__trigger').click();
  await page.getByRole('searchbox',{name:'Search users…'}).fill('Ali');
  await expect(page.getByRole('option',{name:/Alice Example/})).toBeVisible();
  await page.getByRole('searchbox',{name:'Search users…'}).press('Escape');
  await dialog.getByRole('heading',{name:'Add people',exact:true}).click();
  await expect(page.getByRole('searchbox',{name:'Search users…'})).toBeHidden();

  await dialog.getByRole('tab',{name:'Invite by email',exact:true}).click();
  const inputHeight=await dialog.getByRole('textbox',{name:'Email address'}).evaluate(el=>el.offsetHeight);
  const roleHeight=await dialog.locator('.select__trigger').evaluate(el=>el.offsetHeight);
  assert.equal(inputHeight,pickerHeight);assert.equal(roleHeight,pickerHeight);
  const roleAlignment=await dialog.locator('.select__trigger').evaluate(el=>{const value=el.querySelector('[data-slot=select-value]').getBoundingClientRect(),trigger=el.getBoundingClientRect();return Math.abs(value.y+value.height/2-trigger.y-trigger.height/2);});
  assert.ok(roleAlignment<1.5,'Role value is vertically centered');

  await dialog.getByRole('textbox',{name:'Email address'}).fill('test@example.test');
  await dialog.getByRole('button',{name:'Send invitation',exact:true}).click();
  await expect(dialog.getByRole('status')).toHaveText('Access updated.');
  assert.equal(requests.filter(r=>r.path.endsWith('/invitations')&&r.method==='POST').length,1);
  await dialog.getByRole('button',{name:'Close',exact:true}).last().click();
  await page.getByRole('tab',{name:'Invitations',exact:true}).click();
  const table=page.locator('.fms-members-table table');
  await expect(table.getByText('guest@example.test')).toBeVisible();
  await page.getByRole('button',{name:'Next',exact:true}).click();
  await expect.poll(()=>requests.filter(r=>r.path.endsWith('/invitations')&&r.query.after==='next').length).toBe(1);
  assert.deepEqual(errors,[]);
 }catch(error){await page.screenshot({path:'.internal/members-failure.png'});console.log({errors,body:await page.locator('.fms-root').innerText()});throw error;}finally{await page.close();}
});

test('Member and invitation tables have distinct contextual empty states',async()=>{
 const {page,errors}=await open(1440,{emptyMembers:true,emptyInvitations:true});
 try{
  await page.locator('.fms-rail').getByRole('button',{name:'Members',exact:true}).click();
  await expect(page.locator('[data-empty-kind=members]')).toHaveText('No members.');
  await page.getByRole('tab',{name:'Invitations',exact:true}).click();
  await expect(page.locator('[data-empty-kind=invitations]')).toHaveText('No invitations.');
  assert.deepEqual(errors,[]);
 }finally{await page.close();}
});
