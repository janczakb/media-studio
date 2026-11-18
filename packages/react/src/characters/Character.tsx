import {memo,useEffect,useMemo,useRef,useState,type CSSProperties} from 'react';
import {animate as animateValue,motion,useMotionValue,useReducedMotion,useSpring,type MotionValue} from 'motion/react';
import {characterOutline,characterPoint,normalizeCharacter,type CharacterConfig,type CharacterState} from './model';
export type CharacterProps={config:CharacterConfig;state?:CharacterState;size?:number;animate?:boolean;label?:string;audioLevel?:number|MotionValue<number>};
function useActive(enabled:boolean){
 const ref=useRef<SVGSVGElement>(null),[active,setActive]=useState(false);
 useEffect(()=>{if(!enabled)return;const el=ref.current;if(!el)return;let visible=false;
 const update=()=>setActive(visible&&!document.hidden);
 const observer=new IntersectionObserver(entries=>{visible=entries[0]?.isIntersecting??false;update();});
 observer.observe(el);document.addEventListener('visibilitychange',update);
 return()=>{observer.disconnect();document.removeEventListener('visibilitychange',update);};},[enabled]);
 return {ref,active:enabled&&active};
}
export const Character=memo(function Character({config:raw,state='idle',size=64,animate=true,label='Assistant',audioLevel}:CharacterProps){
 const c=normalizeCharacter(raw),reduced=useReducedMotion(),{ref,active}=useActive(animate&&!reduced);
 const animated=active&&!reduced&&c.intensity>0,transition={type:'spring' as const,stiffness:220,damping:24};
 const mood=animated?state:'idle';
 const pose=poses[mood];
 const speed=c.personality==='calm'?1.3:c.personality==='energetic'?.8:1;
 const gazeX=useSpring(0,{stiffness:110,damping:22}),gazeY=useSpring(0,{stiffness:110,damping:22});
 useEffect(()=>{
  if(!animated||!c.trackPointer){gazeX.set(0);gazeY.set(0);return;}
  const el=ref.current;if(!el)return;
  const move=(e:PointerEvent)=>{const r=el.getBoundingClientRect(),dx=e.clientX-r.left-r.width/2,dy=e.clientY-r.top-r.height/2;const reach=Math.max(140,r.width*1.3),distance=Math.hypot(dx,dy);const proximity=Math.max(0,Math.min(1,(reach-distance)/(reach*.35)));gazeX.set(Math.max(-12,Math.min(12,dx/(r.width*.45)*12))*proximity);gazeY.set(Math.max(-8,Math.min(8,dy/(r.height*.45)*8))*proximity);};
  const reset=()=>{gazeX.set(0);gazeY.set(0);};window.addEventListener('pointermove',move,{passive:true});window.addEventListener('blur',reset);document.addEventListener('pointerleave',reset);
  return()=>{window.removeEventListener('pointermove',move);window.removeEventListener('blur',reset);document.removeEventListener('pointerleave',reset);};
 },[animated,c.trackPointer,gazeX,gazeY,ref]);

 const shapeMotion=shapeMotions[c.shape];
 const shapeFrames=useMemo(()=>Object.fromEntries(Object.entries({rotate:[0,0],y:[0,0],scaleX:[1,1],scaleY:[1,1],...shapeMotion.frames}).map(([key,values])=>[key,[values[0]!,...values.slice(1).map(n=>key.startsWith('scale')?1+Math.max(-.035,Math.min(.035,n-1))*c.intensity:Math.max(-6,Math.min(6,n))*c.intensity)]])),[shapeMotion,c.intensity]);
 const eye=(right:boolean)=><g transform={'translate('+(right?c.eyeSpacing:-c.eyeSpacing)+' 0) scale('+c.eyeSize+')'}><CharacterEye kind={mood==='surprised'?'surprised':mood==='uncertain'?'confused':mood==='error'?'worried':mood==='success'||mood==='greeting'?'smile':c.eyes} right={right} active={animated} ink={c.ink}/></g>;
 return <motion.svg ref={ref} className="flex-character" data-state={state} data-active={animated} style={{width:size,height:size}} width={size} height={size} viewBox="0 0 200 200" role="img" aria-label={label}>
 <motion.g style={{transformBox:'view-box',transformOrigin:'100px 150px'}} animate={animated?Object.fromEntries(Object.entries(pose.body).map(([k,v])=>[k,[v[0]!,...v.slice(1).map(n=>(k.startsWith('scale')?1+(n-1)*c.intensity:n*c.intensity))]])):{y:0,rotate:0,scaleX:1,scaleY:1}} transition={{duration:pose.duration*speed,repeat:animated&&!['greeting','success','surprised','uncertain','error'].includes(mood)?Infinity:0,ease:'easeInOut',times:pose.times}}>
 <motion.g key={c.shape==='star'?`star-${c.accessory}-${c.hair}`:c.shape} data-shape-motion={c.shape} style={{transformBox:'view-box',transformOrigin:'100px 100px'}} animate={animated?shapeFrames:{rotate:0,y:0,scaleX:1,scaleY:1}} transition={{duration:shapeMotion.duration*speed,repeat:animated?Infinity:0,ease:'easeInOut'}}>
 {c.shape==='star'&&<g className="fc-star-details" style={{'--fc-spin-duration':`${6.83*speed}s`,animationPlayState:animated?'running':'paused'} as CSSProperties}><CharacterAccessory config={c} reduced={!!reduced} active={animated} speed={speed}/></g>}
 <g data-silhouette-motion="" className={c.shape==='star'?'fc-star-silhouette':undefined} style={{'--fc-spin-duration':`${6.83*speed}s`,animationPlayState:animated?'running':'paused'} as CSSProperties}>
 {c.shape!=='star'&&<CharacterAccessory key={`accessory-${c.shape}`} config={c} reduced={!!reduced} active={animated} speed={speed}/>}
 {c.shape==='cloud'?<CharacterCloud key={c.accessory} color={c.color} active={animated} speed={speed} intensity={c.intensity}/>:<motion.path key={`body-${c.shape}`} initial={false} d={characterOutline(c.shape)} fill={c.color} animate={{d:characterOutline(c.shape),fill:c.color}} transition={reduced?{duration:0}:transition}/>}
 </g>
 <g className={c.shape==='star'?'fc-star-fringe':undefined} style={{'--fc-spin-duration':`${6.83*speed}s`,animationPlayState:animated?'running':'paused'} as CSSProperties}><CharacterHair config={c}/></g>
 <motion.g animate={animated?{y:[pose.faceY[0]!+c.faceY,...pose.faceY.slice(1).map(y=>y+c.faceY)],x:[pose.faceX[0]!,...pose.faceX.slice(1)]}:{y:c.faceY,x:0}} transition={{duration:pose.duration*speed,repeat:animated&&!['greeting','success','surprised','uncertain','error'].includes(mood)?Infinity:0,ease:'easeInOut',times:pose.times}} fill={c.ink} stroke={c.ink} strokeLinecap="round" strokeLinejoin="round">
 <g transform="translate(100 92)"><motion.g style={{x:gazeX,y:gazeY}}>
 <motion.g style={{transformOrigin:'0px 0px'}} animate={animated&&mood==='happy'?{scaleY:[1,.6,.6,.85,1,1],y:[0,-1,-1,0,0,0]}:{scaleY:1,y:0}} transition={{duration:4.8,repeat:animated&&mood==='happy'?Infinity:0,times:[0,.15,.4,.6,.8,1],ease:'easeInOut'}}>{eye(false)}{eye(true)}</motion.g>
 {(c.brows!=='none'||mood==='thinking'||mood==='listening')&&<g fill="none" strokeWidth="3.5"><path d={c.brows==='focused'?'M-34 -19 L-19 -15':(c.brows==='curious'||mood==='thinking')?'M-34 -22 Q-26 -29 -19 -22':'M-34 -20 Q-26 -24 -19 -20'}/><path d={c.brows==='focused'?'M19 -15 L34 -19':'M19 -20 Q26 -24 34 -20'}/></g>}
 {c.cheeks&&<g fill="#fff" stroke="none" opacity=".4"><ellipse cx="-43" cy="19" rx="9" ry="5"/><ellipse cx="43" cy="19" rx="9" ry="5"/></g>}
 <g transform="translate(0 28)"><CharacterMouth kind={c.mouth} mood={mood} ink={c.ink} reduced={!animated} audioLevel={audioLevel}/></g>
 {c.accessory==='glasses'&&<g fill="none" strokeWidth="3">{[-1,1].map(sign=><rect key={sign} x={sign*c.eyeSpacing-c.eyeSize*14} y={-c.eyeSize*17} width={c.eyeSize*28} height={c.eyeSize*34} rx="8"/>)}<path d={`M${-c.eyeSpacing+c.eyeSize*14} -4 Q0 -10 ${c.eyeSpacing-c.eyeSize*14} -4`}/></g>}
 {c.accessory==='freckles'&&<g stroke="none" opacity=".55">{[-1,1].flatMap(sign=>[0,1,2].map(i=><circle key={sign+':'+i} cx={sign*(36+i*5)} cy={11+i%2*5} r="1.7"/>))}</g>}
 </motion.g></g>
 </motion.g>
 {mood==='thinking'&&<g fill={c.ink}>{[0,1,2].map(i=><motion.circle key={i} cx={150+i*10} cy={34} r="3" animate={{y:[0,-5,0],opacity:[.25,1,.25]}} transition={{duration:1.4,delay:i*.18,repeat:Infinity}}/>)}</g>}
 {state==='listening'&&animated&&<motion.circle cx="100" cy="100" r="92" fill="none" stroke={c.color} strokeWidth="2" animate={{opacity:[.5,0],scale:[.94,1.05]}} transition={{duration:1.5,repeat:Infinity}}/>}
 </motion.g></motion.g></motion.svg>;
});

// Choreographed holds make each state readable; transforms stay outside React's frame loop.
const basePoses={
 idle:{duration:6,times:[0,.2,.45,.7,1],body:{y:[0,-1.5,0,-1,0],rotate:[0,0,.6,0,0],scaleX:[1,1.008,1,1.004,1],scaleY:[1,1.008,1,1.004,1]},faceX:[0,0,1,0,0],faceY:[0,0,-.5,0,0]},
 listening:{duration:4.8,times:[0,.18,.48,.7,1],body:{y:[0,-2,-2,1,0],rotate:[0,-5,-5,-2,0],scaleX:[1,.98,.98,1.015,1],scaleY:[1,1.025,1.025,.985,1]},faceX:[0,-3,-3,0,0],faceY:[0,-2,-2,1,0]},
 thinking:{duration:5.6,times:[0,.18,.5,.72,1],body:{y:[0,-3,-3,-1,0],rotate:[0,7,7,-3,0],scaleX:[1,.98,.98,1.01,1],scaleY:[1,1.02,1.02,.99,1]},faceX:[0,5,5,-3,0],faceY:[0,-5,-5,-2,0]},
 speaking:{duration:3.6,times:[0,.2,.43,.7,1],body:{y:[0,-2.5,0,-1.5,0],rotate:[0,-2,0,1.6,0],scaleX:[1,1.015,1,.99,1],scaleY:[1,.99,1,1.015,1]},faceX:[0,-1.5,0,1.5,0],faceY:[0,-1,0,-1,0]},
 happy:{duration:4.8,times:[0,.09,.22,.36,.53,.68,.83,1],body:{y:[0,4,-13,1,-5,0,0,0],rotate:[0,-3,5,-3,2,0,0,0],scaleX:[1,1.08,.94,1.045,.98,1,1,1],scaleY:[1,.92,1.08,.96,1.025,1,1,1]},faceX:[0,0,1,-1,0,0,0,0],faceY:[0,2,-4,1,-2,0,0,0]}
};

const poses={...basePoses,
 greeting:{...basePoses.happy,duration:2.1,body:{...basePoses.happy.body,rotate:[0,-9,9,-6,4,0,0,0]}},
 success:{...basePoses.happy,duration:2.4},
 surprised:{...basePoses.listening,duration:2,body:{y:[0,3,-5,-2,0],rotate:[0,0,0,0,0],scaleX:[1,1.06,.92,.97,1],scaleY:[1,.94,1.1,1.03,1]}},
 uncertain:{...basePoses.thinking,duration:2.8,body:{...basePoses.thinking.body,rotate:[0,-8,8,-4,0]}},
 error:{...basePoses.thinking,duration:1.8,body:{...basePoses.thinking.body,rotate:[0,-5,5,-3,0]}}
};

// Every pose shares one cubic centreline. No element swaps or bounding-box scaling.
function mouthContour(width:number,opening:number,curve:number){
 // A continuous centreline with rounded caps keeps equal visual weight at both ends.
 return 'M'+(-width)+' 0 C'+(-width*.65)+' '+curve+' '+(width*.65)+' '+curve+' '+width+' 0';
}
const mouthRest={smile:mouthContour(12,0,13),soft:mouthContour(10,0,7),neutral:mouthContour(9,0,0),open:mouthContour(7,0,17),grin:mouthContour(14,0,16)};
const speechContours=[
 mouthContour(10,0,7),mouthContour(8,0,15),mouthContour(12,0,8),mouthContour(7,0,18),
 mouthContour(10,0,5),mouthContour(10,0,5),mouthContour(11,0,11),mouthContour(7,0,17),
 mouthContour(12,0,7),mouthContour(9,0,13),mouthContour(10,0,7),mouthContour(10,0,7)
];
function CharacterMouth({kind,mood,ink,reduced,audioLevel}:{audioLevel:number|MotionValue<number>|undefined;kind:CharacterConfig['mouth'];mood:CharacterState;ink:string;reduced:boolean}){
 const target=(mood==='happy'||mood==='success'||mood==='greeting')?mouthContour(14,0,16):mood==='uncertain'||mood==='error'?mouthContour(9,0,-4):mouthRest[kind];
 const path=useMotionValue(target);
 useEffect(()=>{
  let disposed=false;let loop:ReturnType<typeof animateValue>|undefined;
  const driven=mood==='speaking'&&audioLevel!==undefined;
  const number=typeof audioLevel==='number'?audioLevel:audioLevel?.get();
  const level=Number.isFinite(number)?Math.max(0,Math.min(1,number!)):0;
  const intro=animateValue(path,driven?mouthContour(10-level*3,0,2+level*18):mood==='speaking'?speechContours[0]!:target,{duration:reduced?0:.28,ease:'easeInOut'});
  void intro.then(()=>{
   if(disposed||reduced||driven)return;
   if(mood==='speaking')loop=animateValue(path,speechContours,{duration:3.6,times:[0,.08,.17,.25,.33,.43,.52,.61,.7,.79,.88,1],repeat:Infinity,ease:'easeInOut'});
   else {
    const coordinates=target.match(/-?\d+(?:\.\d+)?/g)!.map(Number),width=Math.abs(coordinates[0]!),curve=coordinates[3]!;
    loop=animateValue(path,[target,mouthContour(width*.96,0,curve+1.25),target,mouthContour(width*1.025,0,curve-.65),target],{duration:5.6,times:[0,.28,.5,.78,1],repeat:Infinity,ease:'easeInOut'});
   }
  });
  const unsubscribe=driven&&typeof audioLevel==='object'?audioLevel.on('change',level=>{intro.stop();loop?.stop();loop=animateValue(path,mouthContour(10-Math.max(0,Math.min(1,level))*3,0,2+Math.max(0,Math.min(1,level))*18),{duration:.07,ease:'easeOut'});}):undefined;
  return()=>{disposed=true;unsubscribe?.();intro.stop();loop?.stop();};
 },[mood,target,path,reduced,audioLevel]);
 return <motion.path data-character-mouth="" d={path} fill="none" stroke={ink} strokeWidth="11.5" strokeLinecap="round" strokeLinejoin="round"/>;
}

const eyeStyles={
 oval:{w:10,h:14,tilt:0,brow:0},round:{w:11,h:11,tilt:0,brow:0},smile:{w:10,h:5,tilt:0,brow:0},sleepy:{w:8,h:3,tilt:0,brow:0},spark:{w:14,h:15,tilt:0,brow:0},wink:{w:8,h:15,tilt:0,brow:0},
 curious:{w:8,h:15,tilt:0,brow:2},focused:{w:11,h:7.5,tilt:0,brow:3},surprised:{w:8,h:19,tilt:0,brow:1},calm:{w:8,h:3,tilt:0,brow:0},skeptical:{w:7,h:9,tilt:0,brow:2},worried:{w:8,h:15,tilt:0,brow:4},excited:{w:9,h:15,tilt:0,brow:1},shy:{w:7,h:11,tilt:12,brow:0},angry:{w:9,h:8,tilt:0,brow:3},sad:{w:8,h:10,tilt:0,brow:4},confused:{w:8,h:15,tilt:0,brow:2},thinking:{w:8,h:12,tilt:0,brow:2}
};
function CharacterEye({kind,right,active,ink}:{kind:CharacterConfig['eyes'];right:boolean;active:boolean;ink:string}){
 const p=eyeStyles[kind],sign=right?1:-1;
 const flat=(kind==='wink'&&right)||(kind==='skeptical'&&!right);
 const h=flat?2.6:(kind==='curious'||kind==='confused')?(right?10:18):p.h;
 const tilt=kind==='shy'?sign*12:kind==='focused'?sign*-8:kind==='sleepy'?sign*5:0;
 const profile=eyeMotion(kind,right);
 const movement=active?profile.frames:{scaleY:1,scaleX:1,x:0,y:0,rotate:tilt};
 return <g fill={ink} stroke={ink}>
 <motion.g style={{transformOrigin:'0px 0px'}} animate={movement} transition={{duration:profile.duration,repeat:active?Infinity:0,ease:'easeInOut'}}>
 {kind==='smile'||kind==='calm'?<path d={kind==='smile'?'M-11 4 Q0 -12 11 4':'M-11 1 Q0 -7 11 1'} strokeWidth="10.5" fill="none"/>:kind==='spark'?<path d="M-2 -13 C-1 -16 1 -16 2 -13 L4 -7 Q5 -5 7 -4 L12 -2 C16 -1 16 1 12 2 L7 4 Q5 5 4 7 L2 13 C1 16 -1 16 -2 13 L-4 7 Q-5 5 -7 4 L-12 2 C-16 1 -16 -1 -12 -2 L-7 -4 Q-5 -5 -4 -7 Z" stroke="none"/>:<rect x={-p.w} y={-h} width={p.w*2} height={h*2} rx={Math.min(p.w,h)} stroke="none"/>}
 </motion.g>
 {!!p.brow&&<motion.path d={p.brow===3?(right?'M-9 -17 L8 -21':'M-8 -21 L9 -17'):p.brow===4?(right?'M-8 -22 L9 -17':'M-9 -17 L8 -22'):'M-9 -20 Q0 -24 9 -20'} fill="none" strokeWidth="3.2" opacity=".7" animate={active?{y:[0,right&&p.brow===2?-4:-1,0]}:{y:0}} transition={{duration:3.8,repeat:active?Infinity:0,ease:'easeInOut'}}/>}
 </g>;
}

function eyeMotion(kind:CharacterConfig['eyes'],right:boolean){
 const s=right?1:-1;
 const profiles={
  oval:{duration:6,frames:{scaleY:[1,1,.1,1,1],x:[0,0,0,0,0]}},
  round:{duration:4.6,frames:{scaleY:[1,1.12,1,1,1],scaleX:[1,1.07,1,.95,1],x:[0,2,2,-1,0]}},
  smile:{duration:3.5,frames:{y:[0,-2,0,-1,0],scaleY:[1,1.25,1,1.12,1]}},
  sleepy:{duration:7,frames:{scaleY:[1,.7,.2,.2,1],y:[0,1,2,2,0],rotate:[s*4,s*7,s*7,s*4,s*4]}},
  spark:{duration:3,frames:{rotate:[0,s*12,0,s*-6,0],scaleX:[1,1.15,1,.9,1],scaleY:[1,1.15,1,.9,1]}},
  wink:{duration:4.2,frames:{scaleY:right?[1,1,.12,.12,1]:[1,1.12,1.12,1,1],y:[0,-1,-1,0,0]}},
  curious:{duration:5,frames:{rotate:[0,s*10,s*10,0,0],y:right?[0,-3,-3,0,0]:[0,1,1,0,0],x:[0,3,3,-2,0],scaleY:[1,1.1,1.1,1,1]}},
  focused:{duration:4.8,frames:{scaleY:[1,.82,.82,1,1],x:[0,-2,2,2,0]}},
  surprised:{duration:4,frames:{scaleY:[1,1.14,1.14,.7,1],scaleX:[1,1.1,1.1,1,1],y:[0,-2,-2,0,0]}},
  calm:{duration:6.4,frames:{y:[0,1.2,0,-.6,0],scaleY:[1,.75,1,1.1,1]}},
  skeptical:{duration:5.4,frames:{scaleY:right?[1,1.15,1.15,1,1]:[1,.35,.35,1,1],y:right?[0,-2,-2,0,0]:[0,1,1,0,0],x:[0,-2,-2,0,0]}},
  worried:{duration:4.5,frames:{x:[0,-2,2,-1,0],y:[0,-1,0,-1,0],scaleY:[1,1.05,.85,1.05,1]}},
  excited:{duration:2.7,frames:{scaleY:[1,.55,1.2,.8,1],y:[0,-2,0,-2,0],rotate:[0,s*4,0,s*-3,0]}},
  shy:{duration:5.8,frames:{x:[0,s*2,s*2,0,0],y:[0,4,4,1,0],scaleY:[1,.65,.65,.9,1],rotate:[s*12,s*16,s*16,s*12,s*12]}},
  angry:{duration:4.1,frames:{scaleY:[1,.8,.8,1,1],rotate:[s*-8,s*-12,s*-12,s*-8,s*-8],x:[0,s*-1,s*-1,0,0]}},
  sad:{duration:6.1,frames:{y:[0,3,3,1,0],scaleY:[1,.8,.8,.95,1],rotate:[0,s*8,s*8,0,0]}},
  confused:{duration:4.9,frames:{rotate:[0,s*14,s*-6,0,0],scaleY:right?[1,.75,1.1,1,1]:[1,1.15,.85,1,1],x:[0,-2,2,0,0]}},
  thinking:{duration:6.2,frames:{x:[0,4,4,-2,0],y:[0,-5,-5,-2,0],scaleY:[1,.8,.8,1,1]}}
 };
 return profiles[kind];
}

function CharacterHair({config:c}:{config:CharacterConfig}){
 if(c.hair==='none')return null;
 // Compact fringe stays inside the silhouette's stable core, independent of star rotation.
 const y=c.shape==='triangle'?57:c.shape==='wide'?54:42;
 return <g data-character-hair={c.hair} transform={`translate(100 ${y})`} fill={c.hairColor} stroke={c.hairColor} strokeWidth="4" strokeLinecap="round" strokeLinejoin="round">
 {c.hair==='tuft'&&<path d="M-22 12 C-25 4 -17 -6 -7 -8 C-2 -9 0 -15 5 -14 C11 -12 6 -5 4 -2 C13 -8 21 -6 22 -1 C24 6 13 12 5 12 C-4 9 -13 11 -22 12 Z"/>}
 {c.hair==='swept'&&<path d="M-34 17 C-37 5 -28 -9 -13 -13 C3 -18 23 -13 32 -3 C35 1 34 6 29 7 C20 8 16 3 9 4 C-2 5 -2 18 -12 20 C-16 21 -18 17 -16 13 C-23 14 -28 20 -32 20 Q-34 20 -34 17 Z"/>}
 {c.hair==='orbit'&&<g fill="none" strokeWidth="7.5"><path d="M-19 15 C-30 7 -36 -4 -29 -14 C-22 -24 -8 -22 -7 -12 C-6 -6 -13 -2 -18 -7"/><path d="M7 15 C18 6 30 1 29 -10 C28 -20 17 -23 11 -16 C7 -11 10 -5 15 -6"/></g>}
 {c.hair==='mohawk'&&<path d="M-15 15 C-18 8 -13 1 -12 -5 C-11 -10 -6 -10 -4 -6 C-5 -16 -2 -25 3 -24 C8 -24 8 -14 9 -10 C11 -15 16 -13 16 -8 C17 1 13 8 15 15 Q0 10 -15 15 Z"/>}
 {c.hair==='curls'&&<path d="M-32 15 C-40 7 -34 -4 -26 -5 C-27 -16 -12 -21 -5 -14 C3 -25 20 -20 21 -10 C34 -13 42 1 34 10 C33 19 22 22 16 16 C10 23 0 21 -4 16 C-13 24 -23 21 -24 15 Q-29 19 -32 15 Z"/>}
 </g>;
}

function CharacterAccessory({config:c,reduced,active,speed}:{config:CharacterConfig;reduced:boolean;active:boolean;speed:number}){
 const top=useMemo(()=>c.shape==='triangle'?[100,17] as [number,number]:Array.from({length:96},(_,i)=>characterPoint(c.shape,i/96*Math.PI*2)).reduce((best,p)=>p[1]<best[1]-.01?p:best,characterPoint(c.shape,-Math.PI/2)),[c.shape]);
 const anchor=(p:[number,number])=>({x:p[0],y:p[1]+3});
 // Cloud attachments share the exact lobe transform and timeline, without frame subscriptions.
 const attachment=(point:[number,number],child:React.ReactNode)=>{
  if(c.shape!=='cloud')return child;
  const l=cloudLobes.reduce((best,l)=>Math.hypot(point[0]-100-l.x,point[1]-100-l.y)<Math.hypot(point[0]-100-best.x,point[1]-100-best.y)?l:best,cloudLobes[0]!);
  return <g className="fc-cloud-lobe" style={{'--fc-dx':`${l.dx*c.intensity}px`,'--fc-dy':`${l.dy*c.intensity}px`,'--fc-lobe-duration':`${l.duration*speed}s`,animationPlayState:active?'running':'paused'} as CSSProperties}>{child}</g>;
 };
 const t=reduced?{duration:0}:{type:'spring' as const,stiffness:220,damping:24};
 return <g data-character-accessory={c.accessory} fill={c.color} stroke={c.ink} strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
 {c.accessory==='antenna'&&attachment(top,<motion.g initial={false} animate={anchor(top)} transition={t}><path d="M0 5 C0 -7 12 -10 8 -23" fill="none"/><circle cx="8" cy="-25" r="5" fill={c.ink}/></motion.g>)}
 {c.accessory==='leaf'&&attachment(top,<motion.g initial={false} animate={anchor(top)} transition={t}><path d="M0 7 C-3 -5 -17 -25 5 -26 C25 -25 21 -6 0 7 Z" fill={c.color}/><path d="M0 6 Q5 -6 9 -15" fill="none" strokeWidth="2"/></motion.g>)}
 {c.accessory==='halo'&&attachment(top,<motion.g initial={false} animate={{x:top[0],y:top[1]-9}} transition={t}><ellipse rx="23" ry="6" fill="none"/></motion.g>)}
 </g>;
}

const shapeMotions={
 pebble:{duration:7,frames:{scaleX:[1,1.025,1,1],scaleY:[1,1.025,1,1]}},
 bloom:{duration:6,frames:{rotate:[0,8,-4,0],scaleX:[1,1.035,.98,1],scaleY:[1,1.035,.98,1]}},
 squircle:{duration:5.2,frames:{rotate:[0,-3,3,0],scaleX:[1,1.07,.96,1],scaleY:[1,.94,1.04,1]}},
 diamond:{duration:6.8,frames:{rotate:[0,7,0,-7,0],y:[0,-4,0,-2,0]}},
 star:{duration:7.2,frames:{scaleX:[1,1.012,1,1],scaleY:[1,1.012,1,1]}},
 cloud:{duration:6.83,frames:{y:[0,-2,0,0],scaleX:[1,1.018,1,1],scaleY:[1,1.018,1,1]}},
 capsule:{duration:4.8,frames:{rotate:[0,-6,6,0],scaleX:[1,1.09,.95,1],scaleY:[1,.94,1.05,1]}},
 triangle:{duration:5.5,frames:{rotate:[0,-8,8,0],scaleX:[1,1.045,.98,1],scaleY:[1,.96,1.02,1]}},
 hexagon:{duration:8.6,frames:{rotate:[0,6,-6,0],scaleX:[1,1.025,1,1]}},
 pentagon:{duration:6.3,frames:{rotate:[0,-5,5,0],y:[0,-4,0,0]}},
 wide:{duration:5.9,frames:{scaleX:[1,1.06,.97,1],scaleY:[1,.92,1.05,1],rotate:[0,2,-2,0]}}
};

// Overlapping solid lobes form one opaque silhouette: no seams or per-frame React updates.
const cloudLobes=[{x:2,y:-43,dx:5,dy:3,duration:3.9},{x:45,y:-26,dx:-4,dy:5,duration:4.2},{x:52,y:31,dx:5,dy:-3,duration:3.8},{x:-3,y:53,dx:-4,dy:3,duration:4.1},{x:-47,y:31,dx:4,dy:-5,duration:3.7},{x:-52,y:-20,dx:-4,dy:3,duration:4.3}];
function CharacterCloud({color,active,speed,intensity}:{color:string;active:boolean;speed:number;intensity:number}){
 return <g data-cloud-lobes="" fill={color}><circle cx="100" cy="100" r="45"/>{cloudLobes.map((l,i)=><circle key={i} className="fc-cloud-lobe" cx={100+l.x} cy={100+l.y} r="38" style={{'--fc-dx':`${l.dx*intensity}px`,'--fc-dy':`${l.dy*intensity}px`,'--fc-lobe-duration':`${l.duration*speed}s`,animationPlayState:active?'running':'paused'} as CSSProperties}/>)}</g>;
}
