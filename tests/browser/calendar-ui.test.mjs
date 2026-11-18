import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {build} from 'esbuild';
import {chromium,expect} from '@playwright/test';

let browser,server,origin;
before(async()=>{
  const script=await build({stdin:{contents:`
    import {createRoot} from 'react-dom/client';
    import {useState} from 'react';
    import {FilterControls} from './packages/react/src/components/library/BrowseControls';
    import {enableStudioInteractions} from './packages/react/src/interaction-support';
    import en from './packages/react/src/locales/en';
    enableStudioInteractions();
    window.applied={};
    function Fixture({id,portal}){
      const [filters,setFilters]=useState({});
      return <FilterControls filters={filters} customFilters={{}} extensions={{}} portal={portal} t={en}
        apply={value=>{window.applied[id]=value;setFilters(value);}}/>;
    }
    for(const id of ['first','second']){
      const shadow=document.getElementById(id).attachShadow({mode:'open'});
      const link=document.createElement('link');link.rel='stylesheet';link.href='/studio.css';shadow.append(link);
      const portal=document.createElement('div');portal.className='fms-root';shadow.append(portal);
      createRoot(portal).render(<Fixture id={id} portal={portal}/>);
    }
  `,resolveDir:fileURLToPath(new URL('../../',import.meta.url)),loader:'tsx'},bundle:true,write:false,format:'esm',jsx:'automatic'});
  const css=await readFile(new URL('../../packages/react/dist/studio.css',import.meta.url));
  server=createServer((request,response)=>{
    const path=new URL(request.url,'http://localhost').pathname;
    response.writeHead(200,{'Content-Type':path==='/fixture.js'?'text/javascript':path==='/studio.css'?'text/css':'text/html'});
    response.end(path==='/fixture.js'?script.outputFiles[0].contents:path==='/studio.css'?css:'<!doctype html><html><body><div id="first"></div><div id="second" style="margin-top:24px"></div><script type="module" src="/fixture.js"></script></body></html>');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  origin=`http://127.0.0.1:${server.address().port}`;
  browser=await chromium.launch();
});
after(async()=>{await browser?.close();await new Promise(resolve=>server?.close(resolve));});

test('HeroUI date presets and clear controls retain UTC dates across two isolated instances',async()=>{
  const page=await browser.newPage();page.setDefaultTimeout(5000);
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try{
    await page.clock.setFixedTime(new Date('2026-11-07T23:30:00Z'));
    await page.goto(origin);
    const first=page.locator('#first');
    const second=page.locator('#second');
    await first.getByRole('button',{name:'Filters',exact:true}).click();
    const dialog=first.getByRole('dialog',{name:'Filters',exact:true});
    await expect(dialog.locator('[data-slot="date-picker"]')).toHaveCount(2);
    await expect(dialog.locator('[data-slot="date-picker"]').getByRole('spinbutton')).toHaveCount(6);
    await dialog.getByRole('button',{name:'Last 7 days',exact:true}).click();
    await dialog.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.applied.first)).toEqual({dateFrom:'2026-11-01',dateTo:'2026-11-07'});
    await first.getByRole('button',{name:/^Filters/}).click();
    await dialog.getByRole('button',{name:'Clear date: From date',exact:true}).click();
    await dialog.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.applied.first)).toEqual({dateTo:'2026-11-07'});
    await second.getByRole('button',{name:'Filters',exact:true}).click();
    await second.getByRole('button',{name:'Last 30 days',exact:true}).click();
    await second.getByRole('button',{name:'Apply filters',exact:true}).click();
    assert.deepEqual(await page.evaluate(()=>window.applied),{first:{dateTo:'2026-11-07'},second:{dateFrom:'2026-10-09',dateTo:'2026-11-07'}});
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('nested calendar accepts a date with mouse and Enter, closes with Escape and restores trigger focus',async()=>{
  const page=await browser.newPage();page.setDefaultTimeout(5000);
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  try{
    await page.clock.setFixedTime(new Date('2026-11-07T12:00:00Z'));
    await page.goto(origin);
    const first=page.locator('#first');
    await first.getByRole('button',{name:'Filters',exact:true}).click();
    const dialog=first.getByRole('dialog',{name:'Filters',exact:true});
    const trigger=dialog.getByRole('button',{name:'Choose date From date',exact:true});
    await trigger.click();
    const calendar=first.locator('[data-slot="calendar"]');
    await expect(calendar).toBeVisible();
    assert.equal(await calendar.evaluate(element=>Boolean(element.closest('.fms-filter-form'))),true);
    await calendar.getByRole('button',{name:'Monday, November 2, 2026',exact:true}).click();
    await expect(calendar).toHaveCount(0);
    await expect(dialog).toBeVisible();
    await trigger.focus();await page.keyboard.press('Enter');
    await expect(calendar).toBeVisible();
    await page.keyboard.press('ArrowRight');await page.keyboard.press('Enter');
    await expect(calendar).toHaveCount(0);
    await dialog.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.applied.first)).toEqual({dateFrom:'2026-11-03'});
    await first.getByRole('button',{name:/^Filters/}).click();
    await trigger.click();await expect(calendar).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(calendar).toHaveCount(0);await expect(dialog).toBeVisible();await expect(trigger).toBeFocused();
    await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0);
    assert.deepEqual(errors,[]);
  }finally{await page.close();}
});

test('segmented date fields support keyboard replacement without a native date popup',async()=>{
  const page=await browser.newPage();page.setDefaultTimeout(5000);
  try{
    await page.clock.setFixedTime(new Date('2026-11-07T12:00:00Z'));
    await page.goto(origin);
    const first=page.locator('#first');
    await first.getByRole('button',{name:'Filters',exact:true}).click();
    const dialog=first.getByRole('dialog',{name:'Filters',exact:true});
    await dialog.getByRole('button',{name:'Last 7 days',exact:true}).click();
    await dialog.getByRole('button',{name:'Clear date: From date',exact:true}).click();
    for(const [segment,value] of [['month','10'],['day','15'],['year','2025']]){
      const field=dialog.getByRole('spinbutton',{name:`${segment}, From date`,exact:true});
      await field.focus();await field.pressSequentially(value);
    }
    await dialog.getByRole('button',{name:'Apply filters',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>window.applied.first)).toEqual({dateFrom:'2025-10-15',dateTo:'2026-11-07'});
  }finally{await page.close();}
});
