import {useEffect,useRef} from 'react';

/** Small fixed-size signal buffer; paints at 24fps without React state per frame. */
export function DictationWaveform({analyser,label,paused}:{analyser:()=>AnalyserNode|null;label:string;paused:boolean}){
  const canvas=useRef<HTMLCanvasElement>(null),read=useRef(analyser);read.current=analyser;
  useEffect(()=>{const el=canvas.current;if(!el)return;const ctx=el.getContext('2d');if(!ctx)return;let frame=0,last=0;const buffer=new Uint8Array(256),levels=new Float32Array(36);
    const draw=(time:number)=>{frame=requestAnimationFrame(draw);if(time-last<1000/24||document.hidden)return;last=time;const r=el.getBoundingClientRect(),dpr=Math.min(2,window.devicePixelRatio||1);if(el.width!==Math.round(r.width*dpr)||el.height!==Math.round(r.height*dpr)){el.width=Math.round(r.width*dpr);el.height=Math.round(r.height*dpr);}ctx.setTransform(dpr,0,0,dpr,0,0);ctx.clearRect(0,0,r.width,r.height);const node=read.current();if(node&&!paused)node.getByteTimeDomainData(buffer);else buffer.fill(128);const step=r.width/36;ctx.fillStyle='#929599';
      for(let i=0;i<36;i++){let peak=0;for(let j=i*7;j<(i+1)*7;j++)peak=Math.max(peak,Math.abs((buffer[j]??128)-128)/128);levels[i]=(levels[i]??0)*.65+peak*.35;const h=Math.max(2,Math.min(34,(levels[i]??0)*100));ctx.beginPath();ctx.roundRect(i*step+(step-3)/2,(40-h)/2,3,h,2);ctx.fill();}};
    frame=requestAnimationFrame(draw);return()=>cancelAnimationFrame(frame);
  },[paused]);
  return <canvas ref={canvas} className="fms-dictation-wave" role="img" aria-label={label}/>;
}
