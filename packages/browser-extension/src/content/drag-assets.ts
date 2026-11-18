import type {Asset} from '../core/types';
import {httpUrl,inferKind,safeName} from '../core/urls';
/** Standard URI-list payloads contain one URL per line; comments are not assets. */
export function assetsFromUriList(value:string,primary:Asset):Asset[]{
 if(value.length>256000)throw new Error('Too many dragged files.');
 const urls=[...new Set(value.split(/\r?\n/).map(line=>line.trim()).filter(line=>line&&!line.startsWith('#')).map(line=>httpUrl(line)).filter((url):url is string=>!!url))];
 if(urls.length>300)throw new Error('Too many dragged files.');
 if(urls.length<2)return [primary];
 const assets=urls.map(url=>{
  if(url===primary.url||url===primary.previewUrl)return primary;
  const kind=inferKind(url)??'document';const last=new URL(url).pathname.split('/').pop()||'file';
  let name=last;try{name=decodeURIComponent(last);}catch{}
  return {id:url,url,previewUrl:kind==='image'?url:null,name:safeName(name),kind,width:0,height:0,pageUrl:primary.pageUrl,description:''} satisfies Asset;
 });
 return [...new Map(assets.map(item=>[item.url,item])).values()];
}
