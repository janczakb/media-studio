import {test} from 'node:test';
import assert from 'node:assert/strict';
import {createHmac} from 'node:crypto';
import {serve} from '../../packages/cloudflare-worker/src/index.js';
const secret = 'worker-signing-key-for-test-0000001';
function request(overrides = {}, range) {
  const payload = {v:1,aud:'https://edge.example.invalid',exp:Math.floor(Date.now()/1000)+120,disk:'media',key:'tenant/photo.png',cache:'a'.repeat(64),mime:'image/png',name:'photo.png',download:false,grant:'opaque-encrypted-grant',...overrides};
  const ticket = Buffer.from(JSON.stringify(payload)).toString('base64url');
  const signature = createHmac('sha256',secret).update(ticket).digest('base64url');
  return new Request(`https://edge.example.invalid/?ticket=${ticket}&signature=${signature}`,{headers:range ? {Range:range} : {}});
}
function environment() {
  let reads = 0;
  return {TOKEN_KEY:secret,AUTHORIZATION_MODE:'expiry',DISK_BINDINGS:JSON.stringify({media:{binding:'MEDIA',prefix:'root/'}}),MEDIA:{
    async head(key){assert.equal(key,'root/tenant/photo.png');return {size:8};},
    async get(key,options){reads++;assert.equal(key,'root/tenant/photo.png');const value=options?.range ? 'PNG-data'.slice(options.range.offset,options.range.offset+options.range.length) : 'PNG-data';return {size:8,body:new Response(value).body};},
  },get reads(){return reads;}};
}
const context = {waitUntil(promise){return promise;}};
test('worker rejects tampering and expiration before touching R2 or cache',async()=>{
  const env=environment();let hits=0;const cache={async match(){hits++;return null;}};
  assert.equal((await serve(request({exp:1}),env,context,cache)).status,403);
  assert.equal((await serve(request({aud:'https://wrong.example.invalid'}),env,context,cache)).status,403);
  const url=new URL(request().url);url.searchParams.set('signature','invalid');
  assert.equal((await serve(new Request(url),env,context,cache)).status,403);
  assert.equal(env.reads,0);assert.equal(hits,0);
});
test('worker streams exact byte ranges and bounded errors without caching partial video',async()=>{
  const env=environment();let puts=0;const cache={async match(){return null;},async put(){puts++;}};
  const response=await serve(request({},'bytes=2-4'),env,context,cache);
  assert.equal(response.status,206);assert.equal(response.headers.get('Content-Range'),'bytes 2-4/8');assert.equal(await response.text(),'G-d');assert.equal(puts,0);
  assert.equal((await serve(request({},'bytes=99-'),env,context,cache)).status,416);
  assert.equal((await serve(request({},'bytes=0-1,4-5'),env,context,cache)).status,416);
});
test('strict authorization is checked even for cache hits and rejects on service failure',async()=>{
  const env=environment();delete env.AUTHORIZATION_MODE;env.AUTHORIZE_URL='https://studio.example.invalid/delivery/check';
  const originalFetch=globalThis.fetch;let allowed=true;let checks=0;let hits=0;
  globalThis.fetch=async(url,options)=>{assert.equal(url,env.AUTHORIZE_URL);assert.equal(options.headers['X-FMS-Grant'],'opaque-encrypted-grant');checks++;return new Response(null,{status:allowed ? 204 : 403});};
  const cache={async match(){hits++;return new Response('cached',{headers:{'Content-Length':'6'}});}};
  try{
    const response=await serve(request(),env,context,cache);assert.equal(await response.text(),'cached');assert.equal(response.headers.get('Cache-Control'),'private, no-store');
    allowed=false;assert.equal((await serve(request(),env,context,cache)).status,403);
    assert.equal(checks,2);assert.equal(hits,1);assert.equal(env.reads,0);
    globalThis.fetch=async()=>{throw new Error('offline');};
    assert.equal((await serve(request(),env,context,cache)).status,503);assert.equal(hits,1);
  }finally{globalThis.fetch=originalFetch;}
});
test('worker cache keys omit grants and isolate remapped origins',async()=>{
  const env=environment();const keys=[];const cache={async match(key){keys.push(key.url);return new Response('cached',{headers:{'Content-Length':'6'}});}};
  await serve(request(),env,context,cache);
  env.CACHE_NAMESPACE='v2';await serve(request(),env,context,cache);
  assert.notEqual(keys[0],keys[1]);assert.equal(keys[0].includes('ticket='),false);assert.equal(keys[0].includes('grant'),false);
});

test('worker permits embedding only from configured application origins',async()=>{
  const env=environment();env.CORS_ORIGINS='https://studio.example.invalid,https://other.example.invalid';
  const response=await serve(request(),env,context);
  assert.match(response.headers.get('Content-Security-Policy'),/frame-ancestors 'self' https:\/\/studio\.example\.invalid https:\/\/other\.example\.invalid/);
  assert.equal(response.headers.get('Access-Control-Allow-Origin'),null);
});
