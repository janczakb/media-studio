export const shapes=['pebble','bloom','squircle','diamond','star','cloud','capsule','triangle','hexagon','pentagon','wide'] as const;
export const eyes=['oval','round','smile','sleepy','spark','wink','curious','focused','surprised','calm','skeptical','worried','excited','shy','angry','sad','confused','thinking'] as const;
export const mouths=['smile','soft','open','grin','neutral'] as const;
export const brows=['none','soft','curious','focused'] as const;
export const accessories=['none','glasses','freckles','halo','antenna','leaf'] as const;
export const hairstyles=['none','tuft','swept','curls','mohawk','orbit'] as const;
export const states=['idle','listening','thinking','speaking','happy','greeting','success','surprised','uncertain','error'] as const;
export type CharacterState=typeof states[number];
export type CharacterConfig={version:1;personality:'calm'|'curious'|'energetic';intensity:number;trackPointer:boolean;shape:typeof shapes[number];color:string;ink:string;eyes:typeof eyes[number];mouth:typeof mouths[number];brows:typeof brows[number];accessory:typeof accessories[number];hair:typeof hairstyles[number];hairColor:string;cheeks:boolean;eyeSpacing:number;eyeSize:number;faceY:number};
export const defaultCharacter:CharacterConfig={version:1,personality:'curious',intensity:1,trackPointer:true,shape:'bloom',color:'#b9d8fb',ink:'#172739',eyes:'oval',mouth:'smile',brows:'none',accessory:'none',hair:'none',hairColor:'#172739',cheeks:true,eyeSpacing:26,eyeSize:1,faceY:0};
export function normalizeCharacter(input:unknown):CharacterConfig {
 const v=(input&&typeof input==='object'?input:{}) as Record<string,unknown>;
 const one=<T extends string>(key:string,options:readonly T[],fallback:T):T=>options.includes(v[key] as T)?v[key] as T:fallback;
 const color=(key:string,fallback:string)=>typeof v[key]==='string'&&/^#[a-f\d]{6}$/i.test(v[key] as string)?v[key] as string:fallback;
 const number=(key:string,min:number,max:number,fallback:number)=>typeof v[key]==='number'&&Number.isFinite(v[key])?Math.max(min,Math.min(max,v[key] as number)):fallback;
 return {version:1,personality:one('personality',['calm','curious','energetic'] as const,'curious'),intensity:number('intensity',0,1.5,1),trackPointer:typeof v.trackPointer==='boolean'?v.trackPointer:true,shape:one('shape',shapes,defaultCharacter.shape),color:color('color',defaultCharacter.color),ink:color('ink',defaultCharacter.ink),eyes:one('eyes',eyes,defaultCharacter.eyes),mouth:one('mouth',mouths,defaultCharacter.mouth),brows:one('brows',brows,defaultCharacter.brows),accessory:one('accessory',accessories,defaultCharacter.accessory),hair:one('hair',hairstyles,'none'),hairColor:color('hairColor',defaultCharacter.hairColor),cheeks:typeof v.cheeks==='boolean'?v.cheeks:true,eyeSpacing:number('eyeSpacing',18,36,26),eyeSize:number('eyeSize',.7,1.3,1),faceY:number('faceY',-12,12,0)};
}
// Shared geometry is the sole source for body outlines and accessory attachment points.
export function characterPoint(shape:CharacterConfig['shape'],a:number):[number,number]{
 const c=Math.cos(a),s=Math.sin(a);let r=82;
 if(shape==='bloom')r=77+9*Math.cos(6*a);
 if(shape==='star')r=76+12*Math.cos(6*(a+Math.PI/2));
 if(shape==='diamond')r=roundedRadius('diamond',a);
 if(shape==='squircle')r=77/Math.pow(Math.pow(Math.abs(c),4)+Math.pow(Math.abs(s),4),.25);
 if(shape==='triangle'||shape==='hexagon'||shape==='pentagon'){r=roundedRadius(shape,a);}
 let x=r*c,y=r*s;
 if(shape==='wide'){const q=Math.pow(Math.pow(Math.abs(c),5)+Math.pow(Math.abs(s),5),.2);x=84*c/q;y=62*s/q;}
 if(shape==='capsule')x*=.87;
 if(shape==='cloud'){
 let edge=45;for(const [cx,cy] of [[2,-43],[45,-26],[52,31],[-3,53],[-47,31],[-52,-20]]){const projection=cx!*c+cy!*s,disc=38*38-(cx!*cx!+cy!*cy!-projection*projection);if(disc>=0)edge=Math.max(edge,projection+Math.sqrt(disc));}x=edge*c;y=edge*s;
 }

 return [100+x,100+y];
}
export function characterOutline(shape:CharacterConfig['shape']):string {
 if(shape==='triangle')return 'M88 28 Q100 7 112 28 L188 160 Q201 183 175 183 L25 183 Q-1 183 12 160 Z';
 return Array.from({length:96},(_,i)=>{
 const [x,y]=characterPoint(shape,i/96*Math.PI*2-Math.PI/2);
 return (i?'L':'M')+x.toFixed(3)+','+y.toFixed(3);
 }).join(' ')+' Z';
}

const roundedBodies=new Map<string,[number,number][]>();
function roundedRadius(shape:string,a:number){
 let points=roundedBodies.get(shape);
 if(!points){
 const n=shape==='diamond'||shape==='cloud'?4:shape==='triangle'?3:shape==='hexagon'?6:5;
 const vertices=shape==='triangle'?[[0,-98],[99,83],[-99,83]] as [number,number][]:shape==='cloud'?[[-76,-3],[76,-3],[76,48],[-76,48]] as [number,number][]:Array.from({length:n},(_,i):[number,number]=>{const t=-Math.PI/2+i/n*Math.PI*2;return [Math.cos(t)*(shape==='triangle'?88:82),Math.sin(t)*(shape==='triangle'?88:82)];});
 points=[];
 for(let i=0;i<n;i++){
 const prev=vertices[(i+n-1)%n]!,v=vertices[i]!,next=vertices[(i+1)%n]!;
 const towards=(p:[number,number]):[number,number]=>{const len=Math.hypot(p[0]-v[0],p[1]-v[1]),d=shape==='triangle'?20:14;return [v[0]+(p[0]-v[0])*d/len,v[1]+(p[1]-v[1])*d/len];};
 const start=towards(prev),end=towards(next);
 for(let j=0;j<=12;j++){const t=j/12,u=1-t;points.push([u*u*start[0]+2*u*t*v[0]+t*t*end[0],u*u*start[1]+2*u*t*v[1]+t*t*end[1]]);}
 }
 roundedBodies.set(shape,points);
 }
 const c=Math.cos(a),s=Math.sin(a);let hit=Infinity;
 for(let i=0;i<points.length;i++){const p=points[i]!,q=points[(i+1)%points.length]!,ex=q[0]-p[0],ey=q[1]-p[1],d=c*ey-s*ex;if(Math.abs(d)<1e-8)continue;const r=(p[0]*ey-p[1]*ex)/d,u=(p[0]*s-p[1]*c)/d;if(r>0&&u>=0&&u<=1)hit=Math.min(hit,r);}
 return Number.isFinite(hit)?hit:80;
}
