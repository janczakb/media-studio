import {useEffect,useRef,useState} from 'react';
/** Smooth only the active answer; the stored transcript is never replayed or delayed. */
export function useStreamingText(target:string):string {
  const [visible,setVisible]=useState(''),shown=useRef('');
  useEffect(()=>{
    if(!target.startsWith(shown.current)){shown.current='';setVisible('');}
    if(!target){shown.current='';setVisible('');return;}
    if(window.matchMedia('(prefers-reduced-motion: reduce)').matches){shown.current=target;setVisible(target);return;}
    let frame=0,last=0;
    const draw=(time:number)=>{const delta=last?Math.min(40,time-last):16;last=time;const remaining=target.length-shown.current.length;const step=Math.max(1,Math.ceil(remaining*.14),Math.round(delta*.18));let end=Math.min(target.length,shown.current.length+step);if(end<target.length&&/[\uD800-\uDBFF]/.test(target[end-1]??''))end++;shown.current=target.slice(0,end);setVisible(shown.current);if(end<target.length)frame=requestAnimationFrame(draw);};
    frame=requestAnimationFrame(draw);return()=>cancelAnimationFrame(frame);
  },[target]);
  return visible;
}
