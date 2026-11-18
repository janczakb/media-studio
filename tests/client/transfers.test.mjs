import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createTransferQueue,createUploadTransport,TransferHttpError} from '../../packages/react/dist/internal/uploads/index.js';
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function until(fn){for(let i=0;i<200;i++){if(fn())return;await sleep(10);}throw new Error('Transfer condition timed out');}
const entry={id:'entry',name:'one.txt',kind:'file',revision:1,trashedAt:null};
const policy={enabled:true,chunkBytes:65536,maxBytes:10000000,maxParts:1000,maxConcurrent:3,ordinaryMaxBytes:10000000};
function transport(overrides={}){return {policy:async()=>policy,resolveFolders:async(w,p,paths)=>({items:paths.map(path=>({path,id:'folder'}))}),ordinary:async(w,p,f,k,s,progress)=>{progress(f.size);return entry;},createSession:async(w,p,f)=>({id:'session',state:'uploading',size:f.size,chunkBytes:65536,acceptedParts:[],entry:null}),session:async()=>{throw new Error('unexpected resume')},part:async()=>{},complete:async()=>entry,cancel:async()=>{},...overrides};}
test('queue honors server concurrency, coalesces policy, isolates item updates, caps retained records',async()=>{
 let running=0,max=0,policies=0;
 const q=createTransferQueue({concurrency:4,maxRetainedCompleted:2,transport:transport({policy:async()=>{policies++;return {...policy,maxConcurrent:1}},ordinary:async(w,p,f,k,s,progress)=>{running++;max=Math.max(max,running);progress(1);await sleep(30);progress(f.size);running--;return {...entry,id:k}}})});
 try{q.enqueue(Array.from({length:8},(_,i)=>new File(['data'],`${i}.txt`)),{workspaceId:'one',parentId:null});await until(()=>q.getSnapshot().activeCount===0&&q.getSnapshot().ids.length===2);assert.equal(max,1);assert.equal(policies,1);assert.equal(q.getSnapshot().completedCount,2);assert.equal(q.getSnapshot().ids.length,2);}finally{q.destroy()}
});
test('pause followed immediately by resume keeps one request and preserves idempotency key',async()=>{
 const keys=[];let aborted=0;
 const q=createTransferQueue({concurrency:1,transport:transport({ordinary:async(w,p,f,key,s)=>{keys.push(key);if(keys.length===1)await new Promise((resolve,reject)=>s.addEventListener('abort',()=>{aborted++;reject(new DOMException('Paused','AbortError'))},{once:true}));return entry;}})});
 try{const [id]=q.enqueue([new File(['data'],'one.txt')],{workspaceId:'one',parentId:null});await until(()=>keys.length===1);q.pause(id);q.resume(id);await until(()=>q.getItem(id)?.state==='completed');assert.equal(aborted,1);assert.equal(keys.length,2);assert.equal(keys[0],keys[1]);}finally{q.destroy()}
});
test('chunk resume queries accepted offsets and never resends accepted bytes',async()=>{
 const accepted=new Set();let partCalls=[];let cancelled=false;
 const q=createTransferQueue({concurrency:1,chunkThresholdBytes:1,transport:transport({part:async(w,id,index,b,s,progress)=>{partCalls.push(index);if(index===1&&partCalls.length===2)await new Promise((resolve,reject)=>s.addEventListener('abort',()=>reject(new DOMException('Paused','AbortError')),{once:true}));accepted.add(index);progress(b.size);},session:async()=>({id:'session',state:'uploading',size:140000,chunkBytes:65536,acceptedParts:[...accepted],entry:null}),cancel:async()=>{cancelled=true;}})});
 try{const[id]=q.enqueue([new File(['x'.repeat(140000)],'large.txt')],{workspaceId:'one',parentId:null});await until(()=>partCalls.length===2);q.pause(id);q.resume(id);await until(()=>q.getItem(id)?.state==='completed');assert.deepEqual(partCalls,[0,1,1,2]);assert.equal(q.getItem(id).loaded,140000);assert.equal(cancelled,false);}finally{q.destroy()}
});
test('folder destinations are resolved once and displayed path remains stable on retry',async()=>{
 let resolved=0,attempt=0;const file=new File(['data'],'one.txt');Object.defineProperty(file,'webkitRelativePath',{value:'Launch/Images/one.txt'});
 const q=createTransferQueue({retries:0,transport:transport({resolveFolders:async(w,p,paths)=>{resolved++;assert.equal(p,'root');assert.deepEqual(paths,['Launch/Images']);return{items:[{path:paths[0],id:'folder'}]}},ordinary:async(w,p)=>{assert.equal(p,'folder');if(attempt++===0)throw new TransferHttpError(409,'name conflict');return entry;}})});
 try{const[id]=q.enqueue([file],{workspaceId:'one',parentId:'root'});await until(()=>q.getItem(id)?.state==='error');q.retry(id);await until(()=>q.getItem(id)?.state==='completed');assert.equal(resolved,1);assert.equal(q.getItem(id).relativePath,'Launch/Images/one.txt');}finally{q.destroy()}
});
test('bounded admission is atomic; callbacks cannot turn an accepted upload into a failure',async()=>{
 const q=createTransferQueue({maxFiles:2,transport:transport(),onComplete(){throw new Error('Host callback failed')}});
 try{assert.throws(()=>q.enqueue([new File(['x'],'one'),new File(['x'],'two'),new File(['x'],'three')],{workspaceId:'one',parentId:null}),/full/);assert.equal(q.getSnapshot().ids.length,0);const unsafe=new File(['x'],'one');Object.defineProperty(unsafe,'webkitRelativePath',{value:'../one'});assert.throws(()=>q.enqueue([unsafe],{workspaceId:'one',parentId:null}),/Unsafe/);const[id]=q.enqueue([new File(['x'],'one')],{workspaceId:'one',parentId:null});await until(()=>q.getItem(id)?.state==='completed');assert.equal(q.getItem(id).error,null);}finally{q.destroy()}
});

test('multipart sends only signed bytes to storage and refreshes an expired grant before recording the ETag', async () => {
    const calls = [];
    const uploads = [];
    const checksum = 'ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0=';
    const originalFetch = globalThis.fetch;
    const originalXHR = globalThis.XMLHttpRequest;
    const session = { id: 'multipart-id', transport: 's3-multipart', state: 'uploading', size: 3, chunkBytes: 5242880, acceptedParts: [], entry: null };
    globalThis.fetch = async (url, init) => {
        calls.push({ url, init });
        if (url.endsWith('/upload-policy')) {
            return Response.json({ ...policy, multipartThresholdBytes: 1 });
        }
        if (url.endsWith('/upload-sessions') || url.endsWith('/multipart-id')) {
            return Response.json(session);
        }
        if (url.endsWith('/sign')) {
            return Response.json({ url: 'https://storage.example.invalid/part?grant=' + calls.length, headers: { 'x-amz-checksum-sha256': checksum } });
        }
        if (url.endsWith('/receipt')) {
            return Response.json({ accepted: true });
        }
        if (url.endsWith('/complete')) {
            return Response.json(entry);
        }
        throw new Error('Unexpected API request');
    };
    globalThis.XMLHttpRequest = class {
        headers = {};
        upload = {};
        open(method, url) {
            this.method = method;
            this.url = url;
        }
        setRequestHeader(name, value) {
            this.headers[name] = value;
        }
        getResponseHeader(name) {
            return name === 'ETag' ? '"provider-etag"' : null;
        }
        async send(blob) {
            uploads.push({ method: this.method, url: this.url, headers: this.headers, credentials: this.withCredentials, bytes: await blob.text() });
            this.status = uploads.length === 1 ? 403 : 200;
            this.upload.onprogress({ loaded: blob.size, total: blob.size, lengthComputable: true });
            this.onload();
        }
        abort() {
            this.onabort();
        }
    };
    const queue = createTransferQueue({
        transport: createUploadTransport({ baseUrl: '/api', credentials: 'include', csrfToken: () => 'application-token' }),
        concurrency: 1,
        retries: 1,
    });

    try {
        const [id] = queue.enqueue([new File(['abc'], 'one.txt')], { workspaceId: 'workspace', parentId: null });
        await until(() => queue.getItem(id)?.state === 'completed');

        const created = calls.find(call => call.url.endsWith('/upload-sessions'));
        assert.equal(JSON.parse(created.init.body).direct, true);
        const signs = calls.filter(call => call.url.endsWith('/sign'));
        assert.equal(signs.length, 2);
        assert.deepEqual(JSON.parse(signs[0].init.body), { sha256: 'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad' });
        assert.equal(signs[0].init.headers.get('X-CSRF-TOKEN'), 'application-token');
        assert.equal(uploads.length, 2);
        assert.notEqual(uploads[0].url, uploads[1].url);
        for (const upload of uploads) {
            assert.deepEqual(upload.headers, { 'x-amz-checksum-sha256': checksum });
            assert.equal(upload.credentials, false);
            assert.equal(upload.method, 'PUT');
            assert.equal(upload.bytes, 'abc');
        }
        const receipts = calls.filter(call => call.url.endsWith('/receipt'));
        assert.equal(receipts.length, 1);
        assert.deepEqual(JSON.parse(receipts[0].init.body), { etag: '"provider-etag"' });
        assert.equal(queue.getItem(id).loaded, 3);
    } finally {
        queue.destroy();
        globalThis.fetch = originalFetch;
        globalThis.XMLHttpRequest = originalXHR;
    }
});

test('global pause aborts active parts, freezes admission and resumes authoritative offsets for both chunk transports', async () => {
 for (const mode of ['local','s3-multipart']) {
  const accepted = new Map(); const calls = []; let blocked = true; let running = 0; let peak = 0;
  const q = createTransferQueue({concurrency:2,chunkThresholdBytes:1,transport:transport({
   createSession:async(w,p,f,key)=>{accepted.set(key,new Set()); return{id:key,state:'uploading',transport:mode,size:f.size,chunkBytes:65536,acceptedParts:[],entry:null}},
   session:async(w,id)=>({id,state:'uploading',transport:mode,size:140000,chunkBytes:65536,acceptedParts:[...accepted.get(id)],entry:null}),
   part:async(w,id,index,blob,signal,progress,actualMode)=>{
    assert.equal(actualMode,mode); calls.push([id,index]); running++;peak=Math.max(peak,running);
    try {if (blocked && index===1) await new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new DOMException('Paused','AbortError')),{once:true}));
     accepted.get(id).add(index); progress(blob.size);
    }finally{running--}
   }, complete:async(w,id)=>({...entry,id}),
  })});
  try {
   const ids=q.enqueue([new File(['x'.repeat(140000)],'one'),new File(['x'.repeat(140000)],'two')],{workspaceId:'one',parentId:null});
   await until(()=>calls.filter(([,part])=>part===1).length===2);
   q.pauseAll(); const more=q.enqueue([new File(['x'.repeat(140000)],'three')],{workspaceId:'one',parentId:null});
   await until(()=>q.getSnapshot().activeCount===0); const atPause=calls.length;
   q.resume(ids[0]); await sleep(60);
   assert.equal(calls.length,atPause); assert.equal(q.getSnapshot().isPaused,true); assert.equal(q.getSnapshot().pausedCount,3);
   assert.equal(q.getItem(more[0]).state,'paused');
   blocked=false;q.resumeAll();await until(()=>q.getSnapshot().completedCount===3);
   for(const id of accepted.keys()) assert.equal(calls.filter(([key,part])=>key===id&&part===0).length,1);
   assert.equal(peak,2);assert.equal(q.getSnapshot().pendingCount,0);
  }finally{q.destroy()}
 }
});

test('pause all lets a started final commit settle and does not admit the next file',async()=>{
 let finish;let ordinaryCalls=0;let completes=0;
 const q=createTransferQueue({concurrency:1,chunkThresholdBytes:1,transport:transport({complete:async()=>{completes++;await new Promise(resolve=>finish=resolve);return entry;},ordinary:async()=>{ordinaryCalls++;return entry;}})});
 try {
  const ids=q.enqueue([new File(['x'.repeat(140000)],'one'),new File(['x'],'two')],{workspaceId:'one',parentId:null});
  await until(()=>q.getItem(ids[0])?.state==='finalizing');q.pauseAll();finish();
  await until(()=>q.getSnapshot().activeCount===0);
  assert.equal(q.getItem(ids[0]).state,'completed');assert.equal(q.getItem(ids[1]).state,'paused');assert.equal(completes,1);assert.equal(ordinaryCalls,0);
  q.destroy();
 }finally{q.destroy()}
});

test('pause all suppresses automatic backoff retries until resumed',async()=>{
 let attempts=0;
 const q=createTransferQueue({transport:transport({ordinary:async()=>{if(++attempts===1)throw new TransferHttpError(503,'retry');return entry;}})});
 try {const[id]=q.enqueue([new File(['x'],'one')],{workspaceId:'one',parentId:null});await until(()=>attempts===1&&q.getItem(id).state==='queued');q.pauseAll();await sleep(650);assert.equal(attempts,1);q.resumeAll();await until(()=>q.getItem(id).state==='completed');assert.equal(attempts,2);}finally{q.destroy()}
});
