import type {Asset} from '../core/types';
import {ext} from '../core/webextension';
/** At most three decoded previews; the badge always reports the full batch. */
export function CaptureStack({assets,small=false}:{assets:readonly Asset[];small?:boolean}){
 return <span className={'fc-capture-stack'+(small?' is-small':'')} data-count={Math.min(assets.length,3)}>
  {assets.slice(0,3).map((asset,index)=><img key={asset.id+'-'+index} style={{'--stack-index':index} as React.CSSProperties} src={asset.previewUrl??ext.runtime.getURL('capture-folder-outline.svg')} alt="" draggable={false} referrerPolicy="no-referrer" onError={event=>{const image=event.currentTarget;if(image.dataset.fallback)return;image.dataset.fallback='true';image.src=ext.runtime.getURL('capture-folder-outline.svg');}}/>)}
  {assets.length>1&&<span className="fc-capture-count">{assets.length}</span>}
 </span>;
}
