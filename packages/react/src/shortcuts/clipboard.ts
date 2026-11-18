export interface ClipboardLimits {maxFiles:number;maxBytes:number;maxPixels:number}
export const clipboardDefaults:ClipboardLimits={maxFiles:20,maxBytes:25*1024*1024,maxPixels:16_000_000};
export function clipboardLimits(values:Partial<ClipboardLimits>={}):ClipboardLimits {
  const bounded=(value:number|undefined,fallback:number,max:number)=>typeof value==='number'&&Number.isFinite(value)?Math.max(1,Math.min(max,Math.floor(value))):fallback;
  return {maxFiles:bounded(values.maxFiles,20,120),maxBytes:bounded(values.maxBytes,clipboardDefaults.maxBytes,256*1024*1024),maxPixels:bounded(values.maxPixels,16_000_000,40_000_000)};
}
export function clipboardFiles(data:DataTransfer,limits:ClipboardLimits):File[] {
  const files=Array.from(data.files);if(!files.length)for(const item of Array.from(data.items))if(item.kind==='file'){const file=item.getAsFile();if(file)files.push(file);}
  if(files.length>limits.maxFiles||files.some(file=>file.size===0)||files.reduce((n,file)=>n+file.size,0)>limits.maxBytes)throw new Error('clipboardLimit');
  return files.map(file=>{
    if(file.name&&!/^(image\.(png|jpe?g|webp)|blob)$/i.test(file.name))return file;
    const extension=({'image/png':'png','image/jpeg':'jpg','image/webp':'webp','image/avif':'avif'} as Record<string,string>)[file.type]??'bin';
    return new File([file],`clipboard-${new Date().toISOString().replace(/[:.]/g,'-')}-${crypto.randomUUID().slice(0,8)}.${extension}`,{type:file.type,lastModified:Date.now()});
  });
}
async function boundedBlob(response:Response,limit:number,signal:AbortSignal):Promise<Blob>{
  if(!response.ok||!response.body)throw new Error('clipboardUnavailable');
  if(Number(response.headers.get('content-length'))>limit)throw new Error('clipboardLimit');
  const reader=response.body.getReader(),parts:Uint8Array<ArrayBuffer>[]=[];let bytes=0;
  try{while(true){signal.throwIfAborted();const {done,value}=await reader.read();if(done)break;bytes+=value.byteLength;if(bytes>limit)throw new Error('clipboardLimit');parts.push(value as Uint8Array<ArrayBuffer>);}}finally{await reader.cancel();reader.releaseLock();}
  return new Blob(parts,{type:response.headers.get('content-type')?.split(';')[0]??''});
}
/** Called only from an explicit copy command; never reads the clipboard in the background. */
export async function copyImageToClipboard(url:string,limits:ClipboardLimits,signal:AbortSignal):Promise<void>{
  if(!globalThis.ClipboardItem||!navigator.clipboard?.write)throw new Error('clipboardUnavailable');
  const png=(async()=>{const response=await fetch(url,{credentials:'same-origin',signal});const blob=await boundedBlob(response,limits.maxBytes,signal);if(!['image/png','image/jpeg','image/webp','image/avif'].includes(blob.type))throw new Error('clipboardUnavailable');const image=await createImageBitmap(blob);try{signal.throwIfAborted();if(image.width*image.height>limits.maxPixels)throw new Error('clipboardLimit');const canvas=document.createElement('canvas');canvas.width=image.width;canvas.height=image.height;const ctx=canvas.getContext('2d');if(!ctx)throw new Error('clipboardUnavailable');ctx.drawImage(image,0,0);const result=await new Promise<Blob>((resolve,reject)=>canvas.toBlob(value=>value?resolve(value):reject(new Error('clipboardUnavailable')),'image/png'));signal.throwIfAborted();if(result.size>limits.maxBytes)throw new Error('clipboardLimit');return result;}finally{image.close();}})();
  void png.catch(()=>{});
  // Promise-valued payload retains the originating activation on supported browsers.
  await navigator.clipboard.write([new ClipboardItem({'image/png':png})]);
}
export async function readClipboardFiles(limits:ClipboardLimits):Promise<File[]> {
  if(!navigator.clipboard?.read)throw new Error('clipboardUnavailable');
  const items=await navigator.clipboard.read();
  if(items.length>limits.maxFiles)throw new Error('clipboardLimit');
  const files:File[]=[];let bytes=0;
  for(const item of items){const mime=['image/png','image/jpeg','image/webp','image/avif','application/pdf'].find(type=>item.types.includes(type));if(!mime)continue;const blob=await item.getType(mime);bytes+=blob.size;if(bytes>limits.maxBytes||!blob.size)throw new Error('clipboardLimit');const extension=mime==='application/pdf'?'pdf':mime.split('/')[1]!;files.push(new File([blob],`clipboard-${crypto.randomUUID()}.${extension}`,{type:mime}));}
  if(!files.length)throw new Error('clipboardUnavailable');return files;
}
