/** Private R2 delivery. Authorization precedes cache lookup, including cache hits. */
const encoder = new TextEncoder();
const safeInline = /^(image\/(jpeg|png|webp|avif|gif)|video\/[a-z0-9.+-]+|audio\/[a-z0-9.+-]+|application\/pdf)$/;
function decode(value) {
  if (!/^[A-Za-z0-9_-]+$/.test(value)) throw new Error('Invalid token');
  return Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
}
function cors(request, env) {
  const origin = request.headers.get('Origin');
  const allowed = (env.CORS_ORIGINS || '').split(',').map(x => x.trim()).filter(value => {try {const url=new URL(value);return url.protocol === 'https:' && url.origin === value;}catch{return false;}});
  const policy = {'Content-Security-Policy':"default-src 'none'; media-src 'self'; img-src 'self'; frame-ancestors 'self' " + allowed.join(' ') + "; base-uri 'none'"};
  return origin && allowed.includes(origin) ? {'Access-Control-Allow-Origin': origin, 'Access-Control-Allow-Credentials': 'true', 'Access-Control-Expose-Headers':'Content-Length, Content-Range, Accept-Ranges, Content-Disposition', 'Vary':'Origin',...policy} : policy;
}
function headersFor(ticket, extra = {}) {
  const name = ticket.name.replace(/[\x00-\x1f\x7f/\\"%]/g, '_');
  return new Headers({
    'Content-Type': ticket.mime,
    'Content-Disposition': `${ticket.download || !safeInline.test(ticket.mime) ? 'attachment' : 'inline'}; filename="media"; filename*=UTF-8''${encodeURIComponent(name).replace(/[!'()*]/g,c => '%' + c.charCodeAt(0).toString(16).toUpperCase())}`,
    'Cache-Control':'private, no-store', 'Referrer-Policy':'no-referrer',
    'X-Content-Type-Options':'nosniff', 'X-Robots-Tag':'noindex, nofollow',
    'Content-Security-Policy':"default-src 'none'; frame-ancestors 'self'; base-uri 'none'",
    'Accept-Ranges':'bytes', ...extra,
  });
}
export async function serve(request, env, context, cache = null) {
  const responseHeaders = cors(request, env);
  if (request.method === 'OPTIONS') {
    if (!responseHeaders['Access-Control-Allow-Origin']) return new Response(null, {status:403});
    return new Response(null, {status:204, headers:{...responseHeaders,'Access-Control-Allow-Methods':'GET, HEAD','Access-Control-Allow-Headers':'Range','Access-Control-Max-Age':'600'}});
  }
  const reject = status => new Response(null, {status, headers:{...responseHeaders,'Cache-Control':'private, no-store','Referrer-Policy':'no-referrer'}});
  if (!['GET','HEAD'].includes(request.method)) return reject(405);
  let ticket;
  let mapping;
  try {
    const url = new URL(request.url);
    const payload = url.searchParams.get('ticket');
    const signature = url.searchParams.get('signature');
    if (!payload || payload.length > 16384 || !signature || signature.length > 128 || typeof env.TOKEN_KEY !== 'string' || env.TOKEN_KEY.length < 16) return reject(403);
    if (url.searchParams.getAll('ticket').length !== 1 || url.searchParams.getAll('signature').length !== 1 || [...url.searchParams.keys()].some(k => !['ticket','signature'].includes(k))) return reject(403);
    const key = await crypto.subtle.importKey('raw',encoder.encode(env.TOKEN_KEY),{name:'HMAC',hash:'SHA-256'},false,['verify']);
    if (!await crypto.subtle.verify('HMAC',key,decode(signature),encoder.encode(payload))) return reject(403);
    ticket = JSON.parse(new TextDecoder('utf-8', {fatal:true}).decode(decode(payload)));
    const now = Math.floor(Date.now()/1000);
    if (ticket.v !== 1 || ticket.aud !== url.origin + url.pathname.replace(/\/$/,'') || !Number.isSafeInteger(ticket.exp) || ticket.exp <= now || ticket.exp > now + 3600
      || typeof ticket.key !== 'string' || ticket.key.length > 2048 || /[\x00-\x1f\x7f\\]/.test(ticket.key) || ticket.key.split('/').some(x => ['', '.', '..'].includes(x))
      || !/^[a-f0-9]{64}$/.test(ticket.cache) || typeof ticket.name !== 'string' || ticket.name.length > 764 || typeof ticket.mime !== 'string' || /[\r\n]/.test(ticket.mime) || typeof ticket.download !== 'boolean') return reject(403);
    const mappings = JSON.parse(env.DISK_BINDINGS || '{}');
    mapping = Object.hasOwn(mappings,ticket.disk) ? mappings[ticket.disk] : null;
    if (!mapping || !Object.hasOwn(env,mapping.binding) || !env[mapping.binding]?.get) return reject(503);
  } catch { return reject(403); }
  // Strict mode is the default. Stateless expiry is an explicit operator choice.
  if (env.AUTHORIZATION_MODE !== 'expiry') {
    if (typeof ticket.grant !== 'string' || ticket.grant.length > 16384 || !env.AUTHORIZE_URL?.startsWith('https://')) return reject(403);
    try {
      const decision = await fetch(env.AUTHORIZE_URL, {method:'POST',headers:{'X-FMS-Grant':ticket.grant,'Accept':'application/json'},redirect:'error',signal:AbortSignal.timeout(5000)});
      if (decision.status !== 204) {await decision.body?.cancel(); return reject(403);}
    } catch { return reject(503); }
  }
  const bucket = env[mapping.binding];
  const objectKey = (mapping.prefix || '') + ticket.key;
  const range = request.headers.get('Range');
  const cacheUrl = new URL(request.url); cacheUrl.search = '?version=' + ticket.cache;
  const source = await crypto.subtle.digest('SHA-256',encoder.encode(JSON.stringify([mapping.binding,mapping.prefix || '',env.CACHE_NAMESPACE || 'v1'])));
  cacheUrl.searchParams.set('source',Array.from(new Uint8Array(source),x => x.toString(16).padStart(2,'0')).join(''));
  const cacheRequest = new Request(cacheUrl, {method:'GET'});
  // Full small inline objects only; large downloads and range responses never enter this cache.
  if (!range && !ticket.download && cache) {
    const hit = await cache.match(cacheRequest);
    if (hit) {
      const headers = headersFor(ticket, responseHeaders);
      headers.set('Content-Length',hit.headers.get('Content-Length'));
      if (request.method === 'HEAD') await hit.body?.cancel();
      return new Response(request.method === 'HEAD' ? null : hit.body, {headers});
    }
  }
  try {
    if (range) {
      const parts = /^bytes=(\d*)-(\d*)$/.exec(range);
      if (!parts || (!parts[1] && !parts[2])) return reject(416);
      const meta = await bucket.head(objectKey);
      if (!meta) return reject(404);
      let start = parts[1] ? Number(parts[1]) : Math.max(0,meta.size-Number(parts[2]));
      let end = parts[1] && parts[2] ? Math.min(meta.size-1,Number(parts[2])) : meta.size-1;
      if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) || start > end || start >= meta.size || (!parts[1] && Number(parts[2]) === 0)) {
        const failed = reject(416); failed.headers.set('Content-Range', `bytes */${meta.size}`); return failed;
      }
      const object = request.method === 'HEAD' ? null : await bucket.get(objectKey,{range:{offset:start,length:end-start+1}});
      if (request.method !== 'HEAD' && !object) return reject(404);
      return new Response(object?.body ?? null,{status:206,headers:headersFor(ticket,{...responseHeaders,'Content-Length':String(end-start+1),'Content-Range':`bytes ${start}-${end}/${meta.size}`})});
    }
    const object = await bucket[request.method === 'HEAD' ? 'head' : 'get'](objectKey);
    if (!object) return reject(404);
    const headers = headersFor(ticket,{...responseHeaders,'Content-Length':String(object.size)});
    const response = new Response(request.method === 'HEAD' ? null : object.body,{headers});
    if (request.method === 'GET' && !ticket.download && cache && object.size <= Math.max(0,Math.min(10000000,Number(env.MAX_CACHE_BYTES || 2000000)))) {
      const stored = response.clone();
      stored.headers.set('Cache-Control','public, max-age=31536000, immutable');
      for (const key of ['Access-Control-Allow-Origin','Access-Control-Allow-Credentials','Vary']) stored.headers.delete(key);
      context.waitUntil(cache.put(cacheRequest,stored).catch(() => {}));
    }
    return response;
  } catch { return reject(503); }
}
export default {fetch(request, env, context) {return serve(request,env,context,globalThis.caches?.default ?? null);}};
