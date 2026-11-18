import {motionValue} from 'motion';
/** Caller owns the audio context/source. This driver never opens a microphone. */
export function createCharacterAudioDriver(analyser:AnalyserNode,{gain=4}:{gain?:number}={}){
 const level=motionValue(0),samples=new Float32Array(analyser.fftSize);let frame=0,last=0,disposed=false;
 const multiplier=Number.isFinite(gain)?Math.max(.1,Math.min(20,gain)):4;
 function tick(now:number){
  if(disposed||document.hidden)return;
  if(now-last>=33){last=now;analyser.getFloatTimeDomainData(samples);let sum=0;for(const n of samples)sum+=n*n;const rms=Math.sqrt(sum/samples.length);level.set(Math.min(1,Math.max(0,(rms-.008)*multiplier)));}
  frame=requestAnimationFrame(tick);
 }
 function visibility(){cancelAnimationFrame(frame);level.set(0);if(!disposed&&!document.hidden)frame=requestAnimationFrame(tick);}
 document.addEventListener('visibilitychange',visibility);visibility();
 return {level,destroy(){if(disposed)return;disposed=true;cancelAnimationFrame(frame);document.removeEventListener('visibilitychange',visibility);level.set(0);level.destroy();}};
}
