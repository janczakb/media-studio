import type {Asset} from '../core/types';
import {pinterestImageMime, pinterestOriginal, pinterestOriginalAlternatives} from '../core/pinterest-image';
import {ext} from '../core/webextension';
import {permissionPattern,safeName,extensionOf} from '../core/urls';
class SourceError extends Error {constructor(readonly status:number){super('The source returned '+status+'.');}}
class UnexpectedImageError extends Error {constructor(){super('Pinterest did not return an image.');}}

async function download(asset:Asset,limit:number,signal:AbortSignal,progress:(loaded:number,total:number)=>void,browserSession=false,expectedImage=false):Promise<Blob>{
 if(asset.inline){if(!/^data:image\/(png|jpeg|webp);base64,/.test(asset.inline)||asset.inline.length>24_000_000)throw new Error('Invalid inline capture.');const b=await(await fetch(asset.inline,{signal})).blob();if(b.size>limit)throw new Error('Capture exceeds the file size limit.');return b;}
 if(!await ext.permissions.contains({origins:[permissionPattern(asset.url)]}))throw new Error('Allow access to the source website before saving.');
 const response=await fetch(asset.url,{signal:AbortSignal.any([signal,AbortSignal.timeout(25000)]),credentials:browserSession&&new URL(asset.url).origin===new URL(asset.pageUrl).origin?'include':'omit',redirect:'error',referrerPolicy:'no-referrer',cache:'no-store'});
 const reject = async (error: Error): Promise<never> => {
  await response.body?.cancel().catch(() => {});
  throw error;
 };
 if(!response.ok)return reject(new SourceError(response.status));
 const length=Number(response.headers.get('Content-Length')??0);if(length>limit)return reject(new Error('File exceeds the configured download limit.'));
 const type=response.headers.get('Content-Type')?.split(';')[0]?.trim().toLowerCase()??'application/octet-stream';
 if(expectedImage&&type!=='application/octet-stream'&&!type.startsWith('image/'))return reject(new UnexpectedImageError());
 if(type==='text/html')return reject(new Error('The source returned a web page, not a downloadable file.'));
 const reader=response.body?.getReader();if(!reader)throw new Error('The source did not return a file.');const chunks:Uint8Array<ArrayBuffer>[]=[];let size=0;
 try{while(true){const {done,value}=await reader.read();if(done)break;size+=value.byteLength;if(size>limit)throw new Error('File exceeds the configured download limit.');chunks.push(value);progress(size,length);}}finally{await reader.cancel().catch(()=>{});reader.releaseLock();}
 if(size===0)throw new Error('The file is empty.');
 const blob = new Blob(chunks, {type});
 if (!expectedImage) return blob;
 const mime = await pinterestImageMime(blob);
 if (!mime) throw new UnexpectedImageError();
 return mime === type ? blob : blob.slice(0, size, mime);
}
export async function downloadWithFallback(asset:Asset,limit:number,signal:AbortSignal,progress:(loaded:number,total:number)=>void,browserSession:boolean):Promise<Blob>{
  const original = asset.kind === 'image' && !asset.inline && new URL(asset.url).pathname.startsWith('/originals/')
    ? pinterestOriginal(asset.url) : null;
  const variants = original ? pinterestOriginalAlternatives(original) : [];
  const preview = original && asset.previewUrl && pinterestOriginal(asset.previewUrl) ? asset.previewUrl : null;
  const sources = [...new Set([original ?? asset.url, ...variants, asset.fallbackUrl, preview].filter((url): url is string => !!url))];
  for (const [index, url] of sources.entries()) {
    try {
      return await download({...asset, url}, limit, signal, progress, browserSession, !!original);
    } catch (error) {
      const unavailable = error instanceof SourceError && [401, 403, 404, 410].includes(error.status);
      if (signal.aborted || index === sources.length - 1 || !(unavailable || error instanceof TypeError || error instanceof UnexpectedImageError)) throw error;
    }
  }
  throw new Error('No downloadable source.');
}

export function fileName(asset:Asset,blob:Blob):string {
 const type=blob.type.split(';')[0]??'';const mime:Record<string,string>={'image/png':'png','image/jpeg':'jpg','image/webp':'webp','image/avif':'avif','image/svg+xml':'svg','image/gif':'gif','video/mp4':'mp4','video/webm':'webm','audio/mpeg':'mp3','application/pdf':'pdf','text/plain':'txt'};
 const ext=mime[type]||extensionOf(asset.url)||'bin';const name=safeName(asset.name);return name.toLowerCase().endsWith('.'+ext)?name:`${name.replace(/\.[a-zA-Z0-9]{1,8}$/,'')}.${ext}`;
}
