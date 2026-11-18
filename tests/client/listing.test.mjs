import {test} from 'node:test';
import assert from 'node:assert/strict';
import {appendPage} from '../../packages/react/dist/internal/window.js';
import {createHttpTransport,StudioHttpError} from '../../packages/react/dist/internal/http.js';

const page = id => ({items:[{id}],nextCursor:`next-${id}`,previousCursor:`previous-${id}`,hasMore:true,canWrite:true});
test('listing evicts farthest pages in both directions without changing retained objects', () => {
  const pages = Array.from({length:6},(_,i)=>page(String(i)));
  const forward = appendPage(pages,page('6'),'next');
  assert.equal(forward.length,6);
  assert.equal(forward[0],pages[1]);
  const backward = appendPage(forward,page('0'),'previous');
  assert.deepEqual(backward.map(p=>p.items[0].id),['0','1','2','3','4','5']);
  assert.throws(()=>appendPage([],page('x'),'next',0));
});
test('transport encodes scope, propagates cancellation and keeps instance CSRF isolated', async () => {
  const original = globalThis.fetch;
  const calls=[];
  globalThis.fetch = async (url,options) => {calls.push({url,options}); return Response.json({items:[],nextCursor:null,previousCursor:null,hasMore:false,canWrite:true});};
  try {
    const signal = new AbortController().signal;
    const a=createHttpTransport({baseUrl:'/api/a',csrfToken:()=> 'token-a'});
    const b=createHttpTransport({baseUrl:'/api/b',csrfToken:()=> 'token-b'});
    await a.browse('space/a',{parent:null,view:'files',search:'a & b'},'cursor +',signal);
    assert.match(calls[0].url,/space%2Fa/);
    assert.match(calls[0].url,/cursor=cursor\+%2B/);
    assert.equal(calls[0].options.signal,signal);
    await a.createFolder('one',null,'A',signal);
    await b.createFolder('two',null,'B',signal);
    assert.equal(calls[1].options.headers.get('X-CSRF-TOKEN'),'token-a');
    assert.equal(calls[2].options.headers.get('X-CSRF-TOKEN'),'token-b');
    assert.equal(calls[1].options.credentials,'same-origin');
  } finally {globalThis.fetch=original;}
});
test('transport rejects oversized pages and preserves access errors',async()=>{
  const original=globalThis.fetch;
  const api=createHttpTransport({baseUrl:'/api'});
  const signal=new AbortController().signal;
  try {
    globalThis.fetch=async()=>Response.json({items:Array(121).fill({id:'x',name:'x',kind:'file'}),nextCursor:null,previousCursor:null});
    await assert.rejects(api.browse('w',{parent:null,view:'files',search:''},null,signal),/Unsupported listing/);
    globalThis.fetch=async()=>Response.json({message:'Not permitted'},{status:403});
    await assert.rejects(api.workspaces(signal),error=>error instanceof StudioHttpError && error.status===403);
  } finally {globalThis.fetch=original;}
});

test('HTTP transport carries personal actions, browse scope and versioned playback',async()=>{
  const original=globalThis.fetch;
  const calls=[];
  globalThis.fetch=async(url,options)=>{
    calls.push({url:new URL(url,'http://localhost'),options});
    return url.endsWith('/visit')?new Response(null,{status:204}):Response.json({items:[],nextCursor:null,previousCursor:null});
  };
  try{
    const api=createHttpTransport({baseUrl:'/api',csrfToken:()=> 'csrf'});
    const signal=new AbortController().signal;
    const query={parent:'folder/a',view:'files',search:'a & b',sort:'size',direction:'desc',filters:{type:'image',minBytes:0,dateFrom:'2026-11-01'},customFilters:{audience:'members & guests'}};
    await api.browse('w',query,'cursor +',signal);
    await api.neighbors('w','asset/a',query,'previous',signal);
    await api.star('w','asset/a',true,signal);
    await api.visit('w','asset/a',signal);
    await api.bulk('w',{operation:'move',items:[{id:'asset/a',revision:7}],parent:null},signal);
    for(const {url} of calls.slice(0,2)){
      assert.equal(url.searchParams.get('filters[type]'),'image');
      assert.equal(url.searchParams.get('filters[minBytes]'),'0');
      assert.equal(url.searchParams.get('filters[dateFrom]'),'2026-11-01');
      assert.equal(url.searchParams.get('parent'),'folder/a');
      assert.equal(url.searchParams.get('sort'),'size');
      assert.equal(url.searchParams.get('customFilters[audience]'),'members & guests');
    }
    assert.equal(calls[0].url.searchParams.get('direction'),'desc');
    assert.equal(calls[1].url.searchParams.get('direction'),'previous');
    assert.equal(calls[1].url.searchParams.get('sortDirection'),'desc');
    assert.equal(calls[2].options.method,'PUT');
    assert.deepEqual(JSON.parse(calls[2].options.body),{starred:true});
    assert.equal(calls[3].options.method,'POST');
    assert.equal(calls[3].options.headers.get('X-CSRF-TOKEN'),'csrf');
    assert.equal(calls[4].options.method,'POST');
    const move=JSON.parse(calls[4].options.body);assert.match(move.requestId,/^[a-f0-9-]{36}$/);delete move.requestId;
    assert.deepEqual(move,{operation:'move',items:[{id:'asset/a',revision:7}],parent:null});
    assert.equal(api.contentUrl('w','asset/a',false,'version/a'),'/api/workspaces/w/entries/asset%2Fa/versions/version%2Fa/content');
  }finally{globalThis.fetch=original;}
});

import {build} from 'esbuild';
const configModule=await build({entryPoints:['packages/react/src/browse-extensions.ts'],write:false,bundle:true,format:'esm'});
const {validateBrowseExtensions}=await import('data:text/javascript;base64,'+Buffer.from(configModule.outputFiles[0].contents).toString('base64'));
test('custom browse configuration rejects ambiguous or oversized controls before mounting',()=>{
  const filter={id:'audience',label:'Audience',options:[{id:'member',label:'Member'}]};
  const valid={filters:[filter],sorters:[{id:'priority',label:'Priority'}]};
  assert.equal(validateBrowseExtensions(valid),valid);
  assert.deepEqual(validateBrowseExtensions(undefined),{});
  assert.throws(()=>validateBrowseExtensions({filters:[filter,filter]}),/duplicate/);
  assert.throws(()=>validateBrowseExtensions({filters:Array.from({length:9},(_,i)=>({...filter,id:'filter'+i}))}),/limit/);
  assert.throws(()=>validateBrowseExtensions({filters:[{...filter,options:Array.from({length:101},(_,i)=>({id:String(i),label:'Item '+i}))}]}),/limit/);
  assert.throws(()=>validateBrowseExtensions({sorters:[{id:'name',label:'Name replacement'}]}),/replace/);
  assert.throws(()=>validateBrowseExtensions({sorters:[{id:'bad.path',label:'Invalid'}]}),/invalid/);
  assert.throws(()=>validateBrowseExtensions({sorters:[{id:'priority',label:' '}]}),/invalid/);
});


test('multi-value filters preserve each selected value and filter options keep scope and bounds',async()=>{
  const original=globalThis.fetch;
  const calls=[];
  const api=createHttpTransport({baseUrl:'/api'});
  const signal=new AbortController().signal;
  try{
    globalThis.fetch=async(url,options)=>{calls.push({url:new URL(url,'http://localhost'),options});return Response.json({items:[],nextCursor:null,previousCursor:null,hasMore:false,canWrite:false});};
    const query={parent:null,view:'files',search:'',filters:{types:['image','archive'],extensions:['jpg','zip'],tagIds:['tag-a','tag-b'],tagMatch:'all',tagLocale:'pl'}};
    await api.browse('space/a',query,null,signal);
    await api.neighbors('space/a','entry',query,'next',signal);
    for(const {url} of calls){
      assert.equal(url.searchParams.get('filters[types][0]'),'image');
      assert.equal(url.searchParams.get('filters[types][1]'),'archive');
      assert.equal(url.searchParams.get('filters[extensions][1]'),'zip');
      assert.equal(url.searchParams.get('filters[tagIds][1]'),'tag-b');
      assert.equal(url.searchParams.get('filters[tagMatch]'),'all');
      assert.equal(url.searchParams.get('filters[tagLocale]'),'pl');
    }
    await api.browseChoices('space/a','tags',{search:'tree & lake',locale:'pl',cursor:'cursor +'},signal);
    const request=calls.at(-1);
    assert.equal(request.url.pathname,'/api/workspaces/space%2Fa/filter-options');
    assert.equal(request.url.searchParams.get('search'),'tree & lake');
    assert.equal(request.url.searchParams.get('locale'),'pl');
    assert.equal(request.url.searchParams.get('cursor'),'cursor +');
    assert.equal(request.options.signal,signal);
    globalThis.fetch=async()=>Response.json({items:Array(61).fill({id:'x',label:'X'}),nextCursor:null,hasMore:false});
    await assert.rejects(api.browseChoices('w','tags',{},signal),/Unsupported filter options/);
    globalThis.fetch=async()=>Response.json({items:[],nextCursor:{},hasMore:false});
    await assert.rejects(api.browseChoices('w','tags',{},signal),/Unsupported filter options/);
    globalThis.fetch=async()=>Response.json({message:'Not permitted'},{status:403});
    await assert.rejects(api.browseChoices('w','tags',{},signal),error=>error instanceof StudioHttpError&&error.status===403);
  }finally{globalThis.fetch=original;}
});

test('picker thumbnail preset and MIME constraints survive browsing and status refresh', async () => {
  const original=globalThis.fetch;
  const calls=[];
  globalThis.fetch=async(url,options)=>{calls.push({url:new URL(url,'https://studio.test'),options});return Response.json({items:[],hasMore:false,canWrite:false,nextCursor:null,previousCursor:null});};
  try {
    const api=createHttpTransport({baseUrl:'/api'});
    const signal=new AbortController().signal;
    await api.browse('space',{view:'files',parent:null,search:'',thumbnailPreset:'picker-card',filters:{mimeTypes:['image/*','application/pdf']}},null,signal);
    assert.equal(calls[0].url.searchParams.get('thumbnailPreset'),'picker-card');
    assert.equal(calls[0].url.searchParams.get('filters[mimeTypes][0]'),'image/*');
    assert.equal(calls[0].url.searchParams.get('filters[mimeTypes][1]'),'application/pdf');
    await api.status('space',['asset'],signal,{thumbnailPreset:'picker-card'});
    assert.deepEqual(JSON.parse(calls[1].options.body),{ids:['asset'],thumbnailPreset:'picker-card'});
  } finally {globalThis.fetch=original;}
});
