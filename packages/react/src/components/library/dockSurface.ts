const clamp=(value:number,min:number,max:number)=>Math.min(max,Math.max(min,value));
const n=(value:number)=>Math.round(value*100)/100;
/** Horizontal construction, rotated by the renderer for a vertical dock. One closed outline. */
export function dockSurface(length:number,depth:number,center:number,span:number){
 const end=n(length),bottom=`L${end} 32 A20 20 0 0 1 ${end-20} 52 L20 52 A20 20 0 0 1 0 32 L0 20 A20 20 0 0 1 20 0 Z`;
 if(depth<.5)return `M20 0 L${end-20} 0 A20 20 0 0 1 ${end} 20 ${bottom}`;
 const shoulder=Math.min(10,depth/2),breadth=Math.min(span,length-40-2*shoulder),anchor=clamp(center,20+shoulder+breadth/2,length-20-shoulder-breadth/2),start=n(anchor-breadth/2),finish=n(anchor+breadth/2),reach=n(depth),neck=n(shoulder),radius=n(Math.max(0,Math.min(18,reach-neck,breadth/2)));
 return `M20 0 L${n(start-neck)} 0 Q${start} 0 ${start} ${-neck} L${start} ${n(-reach+radius)} Q${start} ${-reach} ${n(start+radius)} ${-reach} L${n(finish-radius)} ${-reach} Q${finish} ${-reach} ${finish} ${n(-reach+radius)} L${finish} ${-neck} Q${finish} 0 ${n(finish+neck)} 0 L${end-20} 0 A20 20 0 0 1 ${end} 20 ${bottom}`;
}
export const dockSpring=(duration:number,bounce=0)=>{const frequency=2*Math.PI/(1.2*duration);return {type:'spring' as const,stiffness:frequency*frequency,damping:2*(1-bounce)*frequency,mass:1,restDelta:.001,restSpeed:.05};};
