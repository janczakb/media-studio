import {test} from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {fileURLToPath} from 'node:url';
const output=await build({entryPoints:[fileURLToPath(new URL('../../packages/react/src/folders/NavigationSync.ts',import.meta.url))],bundle:true,write:false,format:'esm',platform:'node'});
const {NavigationSync}=await import('data:text/javascript;base64,'+Buffer.from(output.outputFiles[0].text).toString('base64'));
const page=(revision,items=[],hasMore=false)=>({revision,nextRevision:items.at(-1)?.revision??revision,items,hasMore,reset:false});
const event=revision=>({revision,kind:'update',payload:{nodes:[]}});
test('a failed later replay page does not acknowledge undelivered earlier changes',async()=>{
 let fail=true;const afters=[],received=[];
 const sync=new NavigationSync('w',{changes:async(_,after)=>{afters.push(after);if(after===0)return page(1);if(after===1)return {...page(3,[event(2)],true),nextRevision:2};if(fail)throw new Error('offline');return page(3,[event(3)]);}},p=>received.push(p));
 await sync.refresh();await sync.refresh();assert.deepEqual(received,[]);
 fail=false;await sync.refresh();assert.deepEqual(afters,[0,1,2,1,2]);assert.deepEqual(received[0].items.map(e=>e.revision),[2,3]);sync.destroy();
});
test('a large backlog is bounded to five pages and requests a view reset',async()=>{
 let count=0,result;
 const sync=new NavigationSync('w',{changes:async(_,after)=>{count++;return after===0?page(1):{...page(10000,[event(after+1)],true),nextRevision:after+1};}},p=>result=p);
 await sync.refresh();await sync.refresh();assert.equal(count,6);assert.equal(result.reset,true);assert.equal(result.items.length,0);assert.equal(result.nextRevision,10000);sync.destroy();
});
test('duplicate admission shares a request and dispose prevents late publications',async()=>{
 let release,signal,calls=0;const received=[];
 const sync=new NavigationSync('w',{changes:async(_,after,s)=>{calls++;signal=s;return new Promise(resolve=>{release=()=>resolve(page(1));});}},p=>received.push(p));
 const first=sync.refresh();const second=sync.refresh();assert.equal(first,second);await Promise.resolve();sync.destroy();assert.equal(signal.aborted,true);release();await first;assert.equal(calls,1);assert.deepEqual(received,[]);
});
