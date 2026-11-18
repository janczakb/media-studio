import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createStudioApi,StudioApiError} from '../../packages/react/dist/api.js';
const workspace='01M6TF7X1MD67JKPT1BRPZSAJ6';
test('API client sends bearer without cookies, refuses redirects and preserves cursors',async()=>{
 const calls=[];
 const api=createStudioApi({baseUrl:'https://studio.example/rest/v1',workspace,token:()=>Promise.resolve('secret'),fetch:async(url,init)=>{calls.push({url,init});return Response.json({items:[],nextCursor:'next',hasMore:true});}});
 await api.files({cursor:'a+/b=',limit:20});
 assert.equal(new URL(calls[0].url).searchParams.get('cursor'),'a+/b=');
 assert.equal(calls[0].init.headers.get('Authorization'),'Bearer secret');
 assert.equal(calls[0].init.credentials,'omit');assert.equal(calls[0].init.redirect,'error');
 assert.throws(()=>createStudioApi({baseUrl:'https://user:pass@example.test',workspace,token:'secret'}));
 await assert.rejects(api.request('https://evil.test'),/relative/);
 await assert.rejects(api.request('../tokens'),/relative/);
 assert.equal(calls.length,1);
});
test('API mutation errors expose retry-after but never silently retry a write',async()=>{
 let calls=0;const api=createStudioApi({baseUrl:'https://studio.example/rest/v1',workspace,token:'secret',fetch:async()=>{calls++;return Response.json({message:'Slow down'},{status:429,headers:{'Retry-After':'12'}});}});
 await assert.rejects(api.createFolder('Test'),e=>e instanceof StudioApiError&&e.status===429&&e.retryAfter===12&&e.message==='Slow down');assert.equal(calls,1);
});
test('API client bounds JSON payloads and cancels an oversized stream',async()=>{
 let cancelled=false;const api=createStudioApi({baseUrl:'https://studio.example/rest/v1',workspace,token:'secret',fetch:async()=>new Response(new ReadableStream({pull(c){c.enqueue(new Uint8Array(2*1024*1024));},cancel(){cancelled=true;}}))});
 await assert.rejects(api.files(),/client limit/);assert.equal(cancelled,true);
});
test('REST navigation API preserves opaque cursors and the caller idempotency key',async()=>{
 const calls=[];const entry='01M6TF7X1MD67JKPT1BRPZSAJ7';
 const api=createStudioApi({baseUrl:'https://studio.example/rest/v1',workspace,token:'secret',fetch:async(url,init)=>{calls.push({url,init});return Response.json({});}});
 await api.folderContext(entry);
 await api.folderAncestors(entry,'a+/b=',500);
 await api.move(entry,null,7,'retryable-operation-key');
 await api.undoNavigation(entry);
 assert.ok(calls[0].url.endsWith('/folders/'+entry+'/context'));
 const ancestors=new URL(calls[1].url);assert.equal(ancestors.searchParams.get('cursor'),'a+/b=');assert.equal(ancestors.searchParams.get('limit'),'100');
 assert.deepEqual(JSON.parse(calls[2].init.body),{revision:7,parent:null,requestId:'retryable-operation-key'});
 assert.ok(calls[3].url.endsWith('/navigation/operations/'+entry+'/undo'));
 assert.equal(calls[3].init.method,'POST');
 assert.throws(()=>api.navigationChanges(-1),/revision/);
});

test('trash waits for bounded durable steps and exposes explicit cancellation without replaying admission',async()=>{
 const calls=[];const entry='01M6TF7X1MD67JKPT1BRPZSAJ7',operation='01M6TF7X1MD67JKPT1BRPZSAJ8';
 let steps=0;
 const api=createStudioApi({baseUrl:'https://studio.example/rest/v1',workspace,token:'secret',fetch:async(url,init)=>{
   calls.push({url,init});
   const state=url.endsWith('/cancel')?'cancelled':url.endsWith('/advance')&&++steps===2?'completed':'preparing';
   return Response.json({treeOperation:{id:operation,state,operation:'trash',discovered:120,examined:state==='completed'?120:20,error:null},items:state==='completed'?[{id:entry,revision:2}]:[]});
 }});
 const result=await api.trash(entry,1);assert.equal(result.id,entry);assert.equal(result.revision,2);
 assert.equal(calls.filter(call=>call.url.endsWith('/trash')).length,1);
 assert.equal(calls.filter(call=>call.url.endsWith('/advance')).length,2);
 assert.ok(JSON.parse(calls[0].init.body).requestId);
 assert.equal((await api.treeOperations.cancel(operation)).treeOperation.state,'cancelled');
});

test('failed folder operation rejects instead of reporting a successful trash',async()=>{
 const api=createStudioApi({baseUrl:'https://studio.example/rest/v1',workspace,token:'secret',fetch:async()=>Response.json({treeOperation:{id:workspace,state:'failed',operation:'trash',discovered:1,examined:0,error:'Referenced item'},items:[]})});
 await assert.rejects(api.trash(workspace,1),/Referenced item/);
});
