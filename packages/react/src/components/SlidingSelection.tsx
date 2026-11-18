import {useLayoutEffect,useRef,type RefObject} from 'react';

/** Layout-local coordinates remain stable while an enclosing modal scales or recenters. */
export function SlidingSelection({host,selector,selection,className}:{host:RefObject<HTMLElement|null>;selector:string;selection:string;className:string}){
 const indicator=useRef<HTMLSpanElement>(null);
 useLayoutEffect(()=>{
  const root=host.current,node=indicator.current;if(!root||!node)return;
  const measure=()=>{const selected=root.querySelector<HTMLElement>(selector);if(!selected){node.style.opacity='0';return;}let x=0,y=0,current:HTMLElement|null=selected;while(current&&current!==root){x+=current.offsetLeft;y+=current.offsetTop;current=current.offsetParent as HTMLElement|null;}if(current!==root)return;node.style.opacity='1';node.style.width=selected.offsetWidth+'px';node.style.height=selected.offsetHeight+'px';node.style.transform=`translate3d(${x}px,${y}px,0)`;};
  measure();const observer=new ResizeObserver(measure);observer.observe(root);const selected=root.querySelector(selector);if(selected)observer.observe(selected);const frame=requestAnimationFrame(()=>{measure();node.dataset.ready='true';});
  return()=>{cancelAnimationFrame(frame);observer.disconnect();};
 },[host,selector,selection]);
 return <span ref={indicator} className={className} aria-hidden="true"/>;
}
