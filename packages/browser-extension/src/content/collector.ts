import type {Asset,AssetKind,CaptureBatch} from '../core/types';
import {siteMetadata,largerImage} from './site-metadata';
import {pinterestOriginal} from '../core/pinterest-image';
import {instagramPostAssets} from './instagram';
import {largestResponsiveImage} from './responsive-image';
import {httpUrl,inferKind,safeName} from '../core/urls';
export const COLLECTION_LIMIT=3000;
function imageSource(el:HTMLImageElement,high:boolean,metadata:ReturnType<typeof siteMetadata>,preview:string|null):string|null {
 const responsive=high?largestResponsiveImage(el,preview):null;
 const custom=el.getAttribute('data-flex-src')||(high?metadata.url:null);const lazy=['data-original','data-src','data-lazy-src','data-url'].map(k=>el.getAttribute(k)).find(v=>v&&httpUrl(v,document.baseURI));
 const anchor=el.closest('a[href]') as HTMLAnchorElement|null;
 return httpUrl(custom||(high&&anchor&&inferKind(anchor.href)==='image'?anchor.href:null)||responsive||lazy||el.currentSrc||el.src,document.baseURI);
}
function fileMediaSource(media: HTMLMediaElement): string | null {
 const sources = [media.currentSrc, media.src, ...Array.from(media.querySelectorAll<HTMLSourceElement>('source[src]'), source => source.src)];
 for (const source of sources) {
  const url = httpUrl(source, media.baseURI);
  if (url && !/\.(?:m3u8|mpd)$/i.test(new URL(url).pathname)) return url;
 }
 return null;
}
function named(el:Element,url:string):string {let last='capture';try{last=decodeURIComponent(new URL(url).pathname.split('/').pop()??'capture');}catch{}return safeName(el.getAttribute('data-flex-name')||el.getAttribute('alt')||el.getAttribute('title')||last);}
export function extractAsset(el:Element,high=true,backgrounds=true):Asset|null {
 if(el.closest('[data-flex-capture]'))return null;
 let url:string|null=null,kind:AssetKind='image',width=0,height=0,inline:string|undefined;
 const metadata=el.matches('img,video,audio,a')?siteMetadata(el,new URL(location.href)):{};
 const preview=el instanceof HTMLImageElement?httpUrl(el.currentSrc||el.src):null;
 if(el instanceof HTMLImageElement){width=el.naturalWidth;height=el.naturalHeight;if(width>0&&height>0&&(width<5||height<5))return null;url=imageSource(el,high,metadata,preview);}
 else if(el instanceof HTMLVideoElement||el instanceof HTMLAudioElement){url=fileMediaSource(el);kind=el instanceof HTMLVideoElement?'video':'audio';if(el instanceof HTMLVideoElement){width=el.videoWidth;height=el.videoHeight;}}
 else if(el instanceof HTMLCanvasElement){
   if(el.width<5||el.height<5||el.width*el.height>2_000_000)return null;
   try{const data=el.toDataURL('image/png');if(data.length>700000||!data.startsWith('data:image/png;'))return null;inline=data;}catch{return null;}
   width=el.width;height=el.height;url=location.href;
 }
 else if(el instanceof HTMLAnchorElement){url=httpUrl(el.href);const type=url?inferKind(url):null;if(!type)return null;kind=type;}
 else if(el.hasAttribute('data-flex-src'))url=httpUrl(el.getAttribute('data-flex-src')??'',document.baseURI);
 else if(backgrounds&&el instanceof HTMLElement){const rect=el.getBoundingClientRect();if(rect.width<8||rect.height<8)return null;const bg=getComputedStyle(el).backgroundImage;const match=/url\(["']?([^"')]+)["']?\)/.exec(bg);if(match?.[1]){url=httpUrl(match[1],document.baseURI);width=Math.round(rect.width);height=Math.round(rect.height);}}
 if(!url)return null;
 const best=kind==='image'&&high&&!inline?largerImage(url):url;
 const fallback=best!==url&&best===pinterestOriginal(url)?url:preview??url;
 const name=safeName(el.getAttribute('data-flex-name')||metadata.name||named(el,url));
 const pageUrl=httpUrl(el.getAttribute('data-flex-url')??'',document.baseURI)||location.href;
 const size=(attribute:string,detected:number)=>{
   const value=Number(el.getAttribute(attribute));return Number.isFinite(value)&&value>0?Math.min(100000,value):detected;
 };
 return {id:inline?crypto.randomUUID():best,url:best,previewUrl:inline??(kind==='image'?preview??url:el instanceof HTMLVideoElement&&el.poster?httpUrl(el.poster,document.baseURI):null),name,kind,
   width:size('data-flex-width',width),height:size('data-flex-height',height),pageUrl,
   description:(el.getAttribute('data-flex-description')||metadata.description||'').slice(0,4000),
   tags:(el.getAttribute('data-flex-tags')??'').split(',').map(v=>v.trim()).filter(Boolean).slice(0,32),...(inline?{inline}:{}),
   ...(best!==url||preview&&preview!==best?{fallbackUrl:fallback}:{})};
}
export async function collect(high=true):Promise<CaptureBatch>{
 const batch = {id: crypto.randomUUID(), title: document.title.slice(0,200), url: location.href, createdAt: Date.now()};
 const instagram = await instagramPostAssets(new URL(batch.url), high);
 if (instagram.length && location.href === batch.url) return {...batch, assets: instagram, truncated: false};
 const assets=new Map<string,Asset>();const roots:ParentNode[]=[document];let visited=0,truncated=false,inlineBytes=0;
 while(roots.length&&visited<20000&&assets.size<COLLECTION_LIMIT){const root=roots.shift()!;const walker=document.createTreeWalker(root,NodeFilter.SHOW_ELEMENT);let node:Node|null;
 while((node=walker.nextNode())&&visited++<20000){const element=node as Element;if(element.shadowRoot)roots.push(element.shadowRoot);const item=extractAsset(element,high);if(item&&!assets.has(item.id)){
    const bytes=item.inline?.length??0;
    if(inlineBytes+bytes<=4_000_000){assets.set(item.id,item);inlineBytes+=bytes;}else truncated=true;
   }if(assets.size>=COLLECTION_LIMIT){truncated=true;break;}if(visited%150===0)await new Promise<void>(resolve=>setTimeout(resolve,0));}}
 return {...batch,url:location.href,assets:[...assets.values()],truncated:truncated||visited>=20000};
}
