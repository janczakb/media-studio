export function httpUrl(input:string,base?:string):string|null {
  if (!input.trim()) return null;
  try {const u=new URL(input,base);if(!['http:','https:'].includes(u.protocol)||u.username||u.password||u.href.length>8192)return null;u.hash='';return u.href;}catch{return null;}
}
export function serverUrl(input:string):URL {
  const value=httpUrl(input);if(!value)throw new Error('Enter the address of your Studio installation.');
  const u=new URL(value);if(u.protocol!=='https:'&&!['localhost','127.0.0.1','[::1]'].includes(u.hostname)&&!u.hostname.endsWith('.test'))throw new Error('Studio requires HTTPS.');
  u.search='';u.hash='';return u;
}
export function permissionPattern(input:string):string {const url=httpUrl(input);if(!url)throw new Error('Unsupported URL.');const u=new URL(url);return `${u.protocol}//${u.hostname}/*`;}
export function safeName(input:string,fallback='capture'):string {return input.normalize('NFC').replace(/[\u0000-\u001f\u007f/\\:*?"<>|]/g,' ').replace(/\s+/g,' ').trim().slice(0,160)||fallback;}
export function extensionOf(input:string,depth=0):string {
 if(depth>3||input.length>8192)return '';
 const data=/^data:([^;,]+)/i.exec(input);if(data)return formatFromMime(data[1]!);
 try{
  const url=new URL(input);
  for(const key of ['fm','format']){const format=url.searchParams.get(key)?.toLowerCase();if(format&&/^(?:avif|png|jpg|jpeg|webp|gif|svg|tiff|bmp|pdf|mp4|webm)$/.test(format))return format==='jpeg'?'jpg':format;}
  const file=url.pathname.split('/').pop()??'';
  const match=/\.([a-z0-9]{1,10})$/i.exec(file);if(match)return match[1]!.toLowerCase().replace(/^jpeg$/,'jpg');
  for(const key of ['url','src','image','file','path']){const source=url.searchParams.get(key);if(source){const nested=new URL(source,url.origin);if(['http:','https:'].includes(nested.protocol)){const format=extensionOf(nested.href,depth+1);if(format)return format;}}}
 }catch{}
 return '';
}
export function formatFromMime(mime:string):string {
 const type=mime.split(';')[0]?.toLowerCase();
 const types:Record<string,string>={'image/jpeg':'jpg','image/png':'png','image/webp':'webp','image/avif':'avif','image/gif':'gif','image/svg+xml':'svg','image/bmp':'bmp','image/tiff':'tiff','application/pdf':'pdf','video/mp4':'mp4','video/webm':'webm','audio/mpeg':'mp3'};
 return types[type??'']??'';
}

export function allowedApiBase(input:string,origin:string):string {const u=new URL(input);if(u.origin!==origin||u.search||u.hash||u.username||u.password)throw new Error('Unexpected API destination.');return u.href.replace(/\/$/,'');}
export function inferKind(url:string):'image'|'video'|'audio'|'document'|null {const e=extensionOf(url);return /^(avif|png|jpe?g|webp|gif|svg|bmp|tiff?)$/.test(e)?'image':/^(mp4|webm|mov|m4v|ogv)$/.test(e)?'video':/^(mp3|wav|ogg|m4a|flac|aac)$/.test(e)?'audio':/^(pdf|docx?|xlsx?|pptx?|md|txt|csv|epub)$/.test(e)?'document':null;}
export async function sha256(value:string|ArrayBuffer):Promise<string>{const digest=await crypto.subtle.digest('SHA-256',typeof value==='string'?new TextEncoder().encode(value):value);return [...new Uint8Array(digest)].map(v=>v.toString(16).padStart(2,'0')).join('');}
export function randomSecret():string {return [...crypto.getRandomValues(new Uint8Array(32))].map(v=>v.toString(16).padStart(2,'0')).join('');}
