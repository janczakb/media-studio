import {before,after,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {chromium,expect} from '@playwright/test';
let server,browser,origin;
before(async()=>{const assets=new Map(await Promise.all(['studio.js','studio.css'].map(async name=>['/'+name,await readFile(new URL('../../packages/react/dist/'+name,import.meta.url))])));server=createServer((req,res)=>{const file=assets.get(req.url);res.writeHead(200,{'Content-Type':req.url.endsWith('.css')?'text/css':file?'text/javascript':'text/html'});res.end(file??'<html><head><meta name="csrf-token" content="fixture-csrf"></head><body><div id="auth"></div></body></html>');});await new Promise(r=>server.listen(0,'127.0.0.1',r));origin='http://127.0.0.1:'+server.address().port;browser=await chromium.launch();});
after(async()=>{await browser?.close();await new Promise(r=>server.close(r));});
for(const width of [1440,390])test(`standalone account form uses HeroUI and submits credentials with CSRF at ${width}px`,async()=>{
 const page=await browser.newPage({viewport:{width,height:900}});const errors=[],requests=[];page.on('pageerror',e=>errors.push(e.message));await page.route('**/studio/auth/login',route=>{requests.push({body:route.request().postDataJSON(),csrf:route.request().headers()['x-csrf-token']});return route.fulfill({status:422,json:{message:'Invalid credentials',errors:{email:['Invalid credentials']}}});});
 try{await page.goto(origin);await page.evaluate(async()=>{const {mountStudioAuth}=await import('/studio.js');window.auth=mountStudioAuth(document.getElementById('auth'),{screen:'login',prefix:location.origin+'/studio/auth',home:location.origin+'/studio',registration:true,primaryColor:'#0866ff',stylesheetUrl:'/studio.css'});});
  await page.getByLabel('Email',{exact:true}).fill('owner@example.test');const password=page.getByLabel('Password',{exact:true});await password.fill('Fixture-Password123');await page.getByRole('button',{name:'Show password',exact:true}).click();await expect(password).toHaveAttribute('type','text');await page.getByRole('button',{name:'Continue',exact:true}).click();await expect(page.getByRole('alert')).toHaveText('Invalid credentials');assert.equal(requests[0].csrf,'fixture-csrf');assert.equal(requests[0].body.password,'Fixture-Password123');assert.deepEqual(errors,[]);const form=await page.locator('form').boundingBox();assert.ok(form.x>=0&&form.x+form.width<=width);
 }finally{await page.close();}
});
