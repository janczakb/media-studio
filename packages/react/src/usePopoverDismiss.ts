import {useEffect} from 'react';

/** Shadow-root aware outside dismissal for non-modal React Aria popovers. */
export function usePopoverDismiss(target:HTMLElement,open:boolean,close:()=>void,id:string,nestedEscapeSelector?:string){
  useEffect(()=>{if(!open)return;const root=target.getRootNode();
    const outside=(event:Event)=>{const inside=event.composedPath().some(node=>node instanceof Element&&node.getAttribute('data-fms-popover')===id);if(!inside)close();};
    const escape=(event:Event)=>{if(event instanceof KeyboardEvent&&event.key==='Escape'){if(nestedEscapeSelector&&event.composedPath().some(node=>node instanceof Element&&node.matches(nestedEscapeSelector)))return;close();}};
    root.addEventListener('pointerdown',outside,true);root.addEventListener('keydown',escape,true);
    if(root!==target.ownerDocument)target.ownerDocument.addEventListener('pointerdown',outside,true);
    return()=>{root.removeEventListener('pointerdown',outside,true);root.removeEventListener('keydown',escape,true);target.ownerDocument.removeEventListener('pointerdown',outside,true);};
  },[target,open,close,id,nestedEscapeSelector]);
}
