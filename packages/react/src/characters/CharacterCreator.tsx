import {useEffect,useRef,useState,type ReactNode} from 'react';
import {Button,ColorSwatchPicker,Slider,Tabs} from '@heroui/react';
import {Character} from './Character';
import {accessories,hairstyles,brows,defaultCharacter,eyes,mouths,normalizeCharacter,shapes,states,type CharacterConfig,type CharacterState} from './model';

const palette=['#b9d8fb','#bce6d0','#fbd7b2','#f7b9bb','#d7c4f5','#f5e592','#c4e4eb','#d9dce5','#f0c8df'];
type Props={value:CharacterConfig;onChange:(value:CharacterConfig)=>void;onSave?:(value:CharacterConfig)=>void;label?:string;embedded?:boolean};
export function CharacterCreator({value,onChange,onSave,label='Your assistant',embedded=false}:Props){
 const [tab,setTab]=useState('shape'),[state,setState]=useState<CharacterState>('idle'),[saved,setSaved]=useState(false);
 const c=normalizeCharacter(value);
 const timer=useRef<ReturnType<typeof setTimeout>|null>(null);
 useEffect(()=>()=>{if(timer.current)clearTimeout(timer.current);},[]);
 function preview(next:CharacterState){if(timer.current)clearTimeout(timer.current);setState(next);if(['greeting','success','surprised','uncertain','error'].includes(next))timer.current=setTimeout(()=>setState('idle'),3000);}
 function sequence(){if(timer.current)clearTimeout(timer.current);setState('thinking');timer.current=setTimeout(()=>{setState('success');timer.current=setTimeout(()=>setState('idle'),2600);},2500);}

 function change(patch:Partial<CharacterConfig>){setSaved(false);onChange(normalizeCharacter({...c,...patch}));}
 const choices=<K extends keyof CharacterConfig>(key:K,items:readonly CharacterConfig[K][],render?:(value:CharacterConfig[K])=>ReactNode)=><div className="fc-choices" role="group" aria-label={key}>{items.map(item=><Button key={String(item)} variant={c[key]===item?'secondary':'ghost'} aria-pressed={c[key]===item} aria-label={key+': '+item} onPress={()=>change({[key]:item})}>{render?render(item):String(item)}</Button>)}</div>;
 const range=(key:'eyeSize'|'eyeSpacing'|'faceY'|'intensity',title:string,min:number,max:number,step:number)=><div className="fc-range"><span>{title}</span><Slider aria-label={title} minValue={min} maxValue={max} step={step} value={c[key]} onChange={v=>change({[key]:Number(v)})}><Slider.Track><Slider.Fill/><Slider.Thumb/></Slider.Track></Slider></div>;
 return <main className={'fc-creator'+(embedded?' is-embedded':'')}>
 {!embedded&&<header className="fc-header"><a href="/" aria-label="Back to Media Studio">FLEX<span> / Characters</span></a><span>Made to move. Designed to be yours.</span></header>}
 <div className="fc-layout">
 <section className="fc-stage" aria-label="Character preview"><div className="fc-heading"><p>MEET YOUR ASSISTANT</p><h1>{label}</h1><span>A little character. A lot of personality.</span></div>
 <div className="fc-hero"><Character config={c} state={state} size={340}/></div>
 {!embedded&&<><div className="fc-states" role="group" aria-label="Preview animation">{states.map(s=><Button size="sm" key={s} variant={state===s?'secondary':'ghost'} aria-pressed={state===s} onPress={()=>preview(s)}>{s}</Button>)}<Button size="sm" variant="ghost" onPress={sequence}>Try a task</Button></div>
 <div className="fc-small"><span>At home at any size</span><div>{[24,32,48].map(size=><div key={size}><Character config={c} size={size} animate={false}/><small>{size}px</small></div>)}</div><div className="fc-agentbar"><Character config={c} size={28} state={state}/><span>Ask your assistant</span><span>↗</span></div></div>
 </>}
 </section>
 <section className="fc-settings" aria-label="Appearance">
 <div className="fc-panel-title"><h2>Make it yours</h2><Button size="sm" variant="ghost" onPress={()=>change(defaultCharacter)}>Reset</Button></div>
 <Tabs {...(embedded?{}:{variant:'secondary' as const})} selectedKey={tab} onSelectionChange={k=>setTab(String(k))}><Tabs.ListContainer><Tabs.List aria-label="Appearance category">{[['shape','Shape'],['face','Face'],['details','Details'],['motion','Motion']].map(([id,text])=><Tabs.Tab id={id!} key={id}>{text}<Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer></Tabs>
 <div className="fc-options">
 {tab==='shape'&&<><h3>Silhouette</h3>{choices('shape',shapes,item=><><Character config={{...c,shape:item}} size={58} animate={false}/><small>{item}</small></>)}<h3>Body color</h3><ColorSwatchPicker aria-label="Body color" variant="circle" size="lg" value={c.color} onChange={color=>change({color:color.toString('hex')})}>{palette.map(color=><ColorSwatchPicker.Item key={color} color={color}><ColorSwatchPicker.Swatch/><ColorSwatchPicker.Indicator/></ColorSwatchPicker.Item>)}</ColorSwatchPicker></>}
 {tab==='face'&&<><h3>Eyes</h3>{choices('eyes',eyes,item=><><Character config={{...c,eyes:item,brows:'none',mouth:'soft',accessory:'none'}} size={52} animate={false}/><small>{item}</small></>)}<h3>Eyebrows</h3>{choices('brows',brows)}<h3>Mouth</h3>{choices('mouth',mouths)}{range('eyeSize','Eye size',.7,1.3,.05)}{range('eyeSpacing','Eye spacing',18,36,1)}{range('faceY','Face position',-12,12,1)}</>}
 {tab==='details'&&<><h3>Finishing touch</h3>{choices('accessory',accessories,item=><><Character config={{...c,accessory:item}} size={62} animate={false}/><small>{item}</small></>)}<h3>Hair</h3>{choices('hair',hairstyles,item=><><Character config={{...c,hair:item,accessory:'none'}} size={62} animate={false}/><small>{item}</small></>)}{c.hair!=='none'&&<><h3>Hair color</h3><ColorSwatchPicker aria-label="Hair color" variant="circle" value={c.hairColor} onChange={color=>change({hairColor:color.toString('hex')})}>{['#172739','#513829','#95613d','#dbad64','#eee0c7','#e3456c','#7548ed','#087f8c','#f26b21','#1769e0','#d527b7','#21a36d'].map(color=><ColorSwatchPicker.Item key={color} color={color}><ColorSwatchPicker.Swatch/><ColorSwatchPicker.Indicator/></ColorSwatchPicker.Item>)}</ColorSwatchPicker></>}<h3>Cheeks</h3><div className="fc-choices"><Button aria-pressed={!c.cheeks} variant={!c.cheeks?'secondary':'ghost'} onPress={()=>change({cheeks:false})}>Clean</Button><Button aria-pressed={c.cheeks} variant={c.cheeks?'secondary':'ghost'} onPress={()=>change({cheeks:true})}>Soft blush</Button></div><h3>Face color</h3><ColorSwatchPicker aria-label="Face color" variant="circle" value={c.ink} onChange={color=>change({ink:color.toString('hex')})}>{['#172739','#302a42','#3b2c23','#ffffff'].map(color=><ColorSwatchPicker.Item key={color} color={color}><ColorSwatchPicker.Swatch/><ColorSwatchPicker.Indicator/></ColorSwatchPicker.Item>)}</ColorSwatchPicker><p className="fc-hint">Simple features stay clear in your toolbar. Animations pause when the character is out of view.</p></>}
 {tab==='motion'&&<><h3>Personality</h3>{choices('personality',['calm','curious','energetic'])}<h3>Follow your cursor</h3><div className="fc-choices"><Button variant={c.trackPointer?'secondary':'ghost'} aria-pressed={c.trackPointer} onPress={()=>change({trackPointer:!c.trackPointer})}>{c.trackPointer?'Following':'Off'}</Button></div>{range('intensity','Movement',0,1.5,.1)}<p className="fc-hint">Try moving the pointer near the character. Reactions play once and return to idle. “Try a task” previews thinking → success → idle.</p></>}
 </div>{!embedded&&<footer className="fc-footer"><span role="status">{saved?'Appearance saved on this device':'Changes preview live'}</span><Button onPress={()=>{onSave?.(c);setSaved(true);}}>Save appearance</Button></footer>}
 </section></div></main>;
}
