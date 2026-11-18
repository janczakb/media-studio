import {after, before, test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium, expect} from '@playwright/test';

let server, browser, origin;
before(async () => {
  const assets = new Map(await Promise.all(['studio.js', 'studio.css', 'studio-mark.svg', 'filament.svg'].map(async name => [name, await readFile(new URL('../../packages/react/dist/' + name, import.meta.url))])));
  server = createServer((request, response) => {
    const file = request.url.slice(1);
    if (assets.has(file)) {
      response.setHeader('Content-Type', file.endsWith('.css') ? 'text/css' : file.endsWith('.svg') ? 'image/svg+xml' : 'text/javascript');
      response.end(assets.get(file)); return;
    }
    response.setHeader('Content-Type', 'text/html');
    response.end(`<!doctype html><html><body style="margin:0"><div id="studio" style="height:100dvh"></div>
      <script type="module">
        import {mountMediaStudio} from '/studio.js';
        const key = btoa(String.fromCharCode(4) + 'a'.repeat(64)).replaceAll('+','-').replaceAll('/','_').replaceAll('=','');
        window.calls = {status:0, subscribe:0, unsubscribe:0, permission:0};
        let subscription = null;
        const registration = {
          active:{scriptURL:location.origin+'/api/push/worker.js'},
          pushManager:{
            async getSubscription(){return subscription;},
            async subscribe(options){
              window.requestedKeyBytes=options.applicationServerKey.length;
              subscription={
                endpoint:'https://fcm.googleapis.com/fcm/send/browser-fixture',
                options:{applicationServerKey:options.applicationServerKey.buffer},
                async unsubscribe(){subscription=null;return true;},
                toJSON(){return {endpoint:this.endpoint,keys:{p256dh:key,auth:'test'}};}
              };
              return subscription;
            }
          }
        };
        Object.defineProperty(navigator, 'serviceWorker', {value:{
          async getRegistration(){return registration;},
          async register(url){window.workerUrl=url;return registration;}
        }});
        Object.defineProperty(window,'Notification',{value:class {
          static permission='default';
          static async requestPermission(){
            window.calls.permission++;
            this.permission=location.search.includes('denied')?'denied':'granted';
            return this.permission;
          }
        }});
        const baseEvents=['mentioned','guest_uploaded','processed'];
        const push = {
          workerUrl:location.origin+'/api/push/worker.js',
          async status(workspace,device){window.calls.status++;return {available:true,publicKey:key,events:JSON.parse(localStorage.getItem('events')??JSON.stringify(baseEvents)),subscribed:device!==null&&window.calls.subscribe>window.calls.unsubscribe};},
          async preferences(workspace,events){localStorage.setItem('events',JSON.stringify(events));return {events};},
          async subscribe(workspace,value,path,locale){window.calls.subscribe++;window.lastDevice={workspace,path,locale};return {subscribed:true};},
          async unsubscribe(){window.calls.unsubscribe++;return {subscribed:false};}
        };
        const transport={
          async workspaces(){return {items:[{id:'main',name:'Main',role:'viewer',permissions:{'notifications.view':true,'settings.view':false,'members.view':false,'shares.view':false}}],canCreate:false};},
          async browse(){return {items:[],hasMore:false};},
          management:{
            browserPush:push,
            async notifications(){return {items:[],nextCursor:null,previousCursor:null,hasMore:false};}
          }
        };
        mountMediaStudio(document.querySelector('#studio'),{transport,syncUrl:true});
      </script></body></html>`);
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  origin = 'http://127.0.0.1:' + server.address().port;
  browser = await chromium.launch();
});
after(async () => {await browser?.close();await new Promise(resolve => server?.close(resolve));});

test('one device switch is shared with settings and event preferences survive a page reload', async () => {
  const page = await browser.newPage({viewport:{width:1200,height:850}});
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try {
    await page.goto(origin+'/?fmsView=notifications&fmsWorkspace=main');
    const toggle=page.getByRole('switch',{name:'Desktop notifications',exact:true});
    await expect(toggle).toBeEnabled();
    assert.equal(await page.evaluate(()=>window.calls.permission),0);
    await toggle.press('Space');
    await expect(toggle).toBeChecked();
    assert.equal(await page.evaluate(()=>window.requestedKeyBytes),65);
    await page.getByRole('button',{name:'Settings',exact:true}).click();
    await page.getByRole('navigation',{name:'Settings',exact:true}).getByRole('button',{name:'Notifications',exact:true}).click();
    const modal=page.getByRole('dialog');
    await expect(modal.getByRole('switch',{name:'Desktop notifications',exact:true})).toBeChecked();
    await expect(modal.getByRole('checkbox',{name:'Mentions in comments',exact:true})).toBeChecked();
    await modal.getByText('Files you upload',{exact:true}).click();
    await expect(modal.getByRole('checkbox',{name:'Files you upload',exact:true})).toBeChecked();
    await expect(page.getByRole('navigation',{name:'Settings',exact:true}).getByRole('button',{name:'Uploads',exact:true})).toHaveCount(0);
    await modal.getByRole('switch',{name:'Desktop notifications',exact:true}).press('Space');
    await expect(modal.getByRole('switch',{name:'Desktop notifications',exact:true})).not.toBeChecked();
    assert.equal(await page.evaluate(()=>window.calls.unsubscribe),1);
    await page.getByRole('button',{name:'Close',exact:true}).click();
    await expect(toggle).not.toBeChecked();
    await page.reload();
    await page.getByRole('button',{name:'Settings',exact:true}).click();
    await page.getByRole('navigation',{name:'Settings',exact:true}).getByRole('button',{name:'Notifications',exact:true}).click();
    await expect(page.getByRole('checkbox',{name:'Files you upload',exact:true})).toBeChecked();
    assert.deepEqual(errors,[]);
  } finally {await page.close();}
});

test('permission refusal leaves the switch off and the user can still use the inbox',async()=>{
  const page=await browser.newPage({viewport:{width:390,height:844}});
  try {
    await page.goto(origin+'/?fmsView=notifications&fmsWorkspace=main&denied');
    const toggle=page.getByRole('switch',{name:'Desktop notifications',exact:true});
    await expect(toggle).toBeEnabled();
    await toggle.press('Space');
    await expect(page.getByRole('alert')).toContainText('Notifications are blocked');
    await expect(toggle).not.toBeChecked();
    assert.equal(await page.evaluate(()=>window.calls.subscribe),0);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth),390);
    await page.getByRole('tab',{name:'Unread',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Notifications',exact:true})).toBeVisible();
  } finally {await page.close();}
});
