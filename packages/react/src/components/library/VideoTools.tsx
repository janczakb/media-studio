import {Button,Input,Label,Slider,TextField,Spinner} from '@heroui/react';
import {PauseIcon,PlayIcon} from '@hugeicons/core-free-icons';
import {useCallback,useEffect,useRef,useState} from 'react';
import type {Entry,StudioOptions,VideoEditCapabilities,VideoEditOperation} from '../../contracts';
import {Icon,errorText,type Messages} from '../../ui';
import {randomId} from '../../random-id';
import {useVideoThumbnails} from './useVideoThumbnails';
import {TrimPlayer} from './TrimPlayer';
import type {VideoPlayerElement} from '@videojs/html/video';

export function VideoTools({entry,workspace,options,t,onChanged,onEditing,onTrimReady}:{entry:Entry;workspace:string;options:StudioOptions;t:Messages;onChanged:(entry:Entry)=>void;onEditing:(value:boolean)=>void;onTrimReady:(start:(()=>void)|null,busy:boolean)=>void}){
  const transport=options.transport;
  const [caps,setCaps]=useState<VideoEditCapabilities|null>(null),[editing,setEditing]=useState(false),[error,setError]=useState('');
  const [operation,setOperation]=useState<VideoEditOperation|null>(null),[saving,setSaving]=useState(false);
  const [duration,setDuration]=useState(0),[range,setRange]=useState<[number,number]>([0,0]);
  const [draft,setDraft]=useState<[string,string]>(['0','0']);
  const controllerRef=useRef<VideoPlayerElement|null>(null);
  const [playhead,setPlayhead]=useState(0);
  const [playing,setPlaying]=useState(false);
  const player=useRef<HTMLVideoElement|null>(null),abort=useRef<AbortController|null>(null);
  const source=transport.contentUrl(workspace,entry.id,false,entry.versionId??undefined);
  const thumbnailCount = Math.max(1, Math.min(12, Math.floor(options.videoEditing?.thumbnailCount ?? 10)));
  const thumbnailSource = entry.previewUrl ?? source;
  const frames = useVideoThumbnails(thumbnailSource, thumbnailCount, editing && options.videoEditing?.timelineThumbnails !== false);
  const tickCount = 5;
  const minimum = caps?.minSelection ?? 3;
  const selectionLength = Math.max(0, range[1] - range[0]);
  const playheadPercent = selectionLength ? (playhead - range[0]) / selectionLength * 100 : 0;
  const rangeValid = duration > 0 && draft.every(value => value.trim() !== '' && Number.isFinite(Number(value)))
    && Number(draft[0]) >= 0 && Number(draft[1]) <= duration + .005
    && selectionLength + .000001 >= minimum && range[1] <= duration;
  const busy=saving||operation?.state==='queued'||operation?.state==='processing';
  useEffect(()=>{
    if(!transport.videoEdit||!transport.trimVideo||options.previewActions===false)return;
    const controller=new AbortController();let timer:ReturnType<typeof setTimeout>|undefined;
    const refresh=async()=>{
      try{
        const value=await transport.videoEdit!(workspace,entry.id,controller.signal);if(controller.signal.aborted)return;
        setCaps(value);setOperation(value.operation);
        if(value.operation?.state==='queued'||value.operation?.state==='processing')timer=setTimeout(()=>void refresh(),2000);
      }catch(reason){if(!controller.signal.aborted)setError(errorText(reason));}
    };
    void refresh();return()=>{controller.abort();if(timer)clearTimeout(timer);};
  },[entry.id,entry.versionId,workspace,transport,options.previewActions]);
  useEffect(()=>()=>{abort.current?.abort();onEditing(false);},[]);
  const openTrim = useCallback(() => {
    setEditing(true);
    onEditing(true);
    setError('');
  }, [onEditing]);

  useEffect(() => {
    onTrimReady(caps?.enabled ? openTrim : null, busy);
  }, [caps?.enabled, openTrim, busy, onTrimReady]);

  const close=()=>{if(!busy){player.current?.pause();setPlaying(false);setEditing(false);onEditing(false);setError('');}};
  const save=async()=>{
    if(!transport.trimVideo||busy||!rangeValid)return;
    const controller=new AbortController();abort.current?.abort();abort.current=controller;setSaving(true);setError('');
    try{
      let job=await transport.trimVideo(workspace,entry.id,{revision:entry.revision,start:range[0],end:range[1],requestId:randomId()},controller.signal);setOperation(job);
      while(job.state==='queued'||job.state==='processing'){
        await new Promise<void>((resolve,reject)=>{const timer=setTimeout(()=>{controller.signal.removeEventListener('abort',cancel);resolve();},2000);const cancel=()=>{clearTimeout(timer);reject(controller.signal.reason);};controller.signal.addEventListener('abort',cancel,{once:true});});
        const value=await transport.videoEdit!(workspace,entry.id,controller.signal);job=value.operation??job;setOperation(job);
      }
      if(job.state==='failed')throw new Error(t.trimFailed);
      const updated=await transport.details(workspace,entry.id,controller.signal);if(controller.signal.aborted)return;
      setEditing(false);onEditing(false);onChanged(updated);
    }catch(reason){if(!controller.signal.aborted)setError(errorText(reason));}finally{if(!controller.signal.aborted)setSaving(false);}
  };
  return <>
    {!editing&&busy&&<div className="fms-video-edit-status" role="status"><Spinner size="sm"/>{operation?.state==='queued'?t.trimQueued:t.trimProcessing}</div>}
    {editing&&<section className="fms-video-trim" aria-label={t.trimVideo}>
      <div className="fms-video-trim-screen"><TrimPlayer showControls={options.videoEditing?.showPlayerControls ?? false} source={source} name={entry.name} media={player} controller={controllerRef} onPlaying={setPlaying} onError={()=>setError(t.previewFailed)} onDuration={d=>{if(Number.isFinite(d)&&d>0){setDuration(d);setRange([0,d]);setDraft(['0.00',d.toFixed(2)]);}}} onTime={time=>{setPlayhead(time);if(time>=range[1])controllerRef.current?.store.pause();}}/></div>
      <div className="fms-video-trim-ruler" aria-hidden="true">
        {Array.from({length:tickCount}, (_, index) => {
          const seconds = Math.floor(duration * index / (tickCount - 1));
          return <span key={index}>{Math.floor(seconds / 60)}:{String(seconds % 60).padStart(2, '0')}</span>;
        })}
      </div>
      <Slider className="fms-video-trim-timeline" aria-label={t.trimLength} minValue={0} maxValue={Math.max(duration,.1)} step={.01} value={range} isDisabled={busy||duration<minimum} onChange={value=>{
        if (!Array.isArray(value)) return;
        const [start = 0, end = duration] = value;
        const next: [number, number] = start !== range[0]
          ? [Math.max(0, Math.min(start, range[1] - minimum)), range[1]]
          : [range[0], Math.min(duration, Math.max(end, range[0] + minimum))];
        setRange(next);
        setDraft([next[0].toFixed(2), next[1].toFixed(2)]);
      }} onChangeEnd={()=>{void controllerRef.current?.store.seek(range[0]);}}>
        <Slider.Track>
          <div className="fms-video-timeline-frames" aria-hidden="true">{Array.from({length:thumbnailCount},(_,index)=>frames[index]?<img key={index} src={frames[index]} alt=""/>:<span key={index} className="fms-video-timeline-placeholder"/>)}</div>
          <div className="fms-video-trim-mask" style={{insetInlineStart:0,width:`${duration?range[0]/duration*100:0}%`}}/>
          <div className="fms-video-trim-mask" style={{insetInlineEnd:0,width:`${duration?(1-range[1]/duration)*100:0}%`}}/>
          <div className="fms-video-trim-selection" aria-hidden="true" style={{
            insetInlineStart: `${duration ? range[0] / duration * 100 : 0}%`,
            width: `${duration ? selectionLength / duration * 100 : 100}%`,
          }}>
            {playheadPercent >= 0 && playheadPercent <= 100 && <div className="fms-video-trim-playhead"
              style={{insetInlineStart: `${playheadPercent}%`}}/>}
          </div>
          {[0,1].map(index=><Slider.Thumb className="fms-video-trim-handle" key={index} index={index} aria-label={index?t.trimEnd:t.trimStart}><span aria-hidden="true" className="fms-video-trim-grip"/></Slider.Thumb>)}
        </Slider.Track>
      </Slider>
      <div className="fms-video-trim-controls">
        <div className="fms-video-trim-fields">
          <Button isIconOnly variant="secondary" aria-label={playing ? t.pauseSelection : t.playSelection} isDisabled={busy||!rangeValid} onPress={()=>{
            const store = controllerRef.current?.store;
            if (!store) return;
            if (playing) {
              store.pause();
              return;
            }
            const time = player.current?.currentTime ?? 0;
            const ready = time < range[0] || time >= range[1] ? store.seek(range[0]) : Promise.resolve();
            void ready.then(() => store.play()).catch(() => setError(t.previewFailed));
          }}><Icon icon={playing ? PauseIcon : PlayIcon}/></Button>
          {[0,1].map(index=><TextField key={index} isDisabled={busy} value={draft[index]??''} onChange={value=>{setDraft(previous=>index?[previous[0],value]:[value,previous[1]]);const n=Number(value);if(value.trim()&&Number.isFinite(n)&&n>=0&&n<=duration)setRange(previous=>index?[previous[0],n]:[n,previous[1]]);}}><Label>{index?t.trimEnd:t.trimStart}</Label><Input variant="secondary" inputMode="decimal"/></TextField>)}
          <span>{t.trimLength}: {Math.max(0,range[1]-range[0]).toFixed(2)}s</span>
        </div>
        <div className="fms-video-trim-footer">
          <Button variant="secondary" isDisabled={busy} onPress={close}>{t.cancel}</Button>
          <Button onPress={()=>void save()} isDisabled={busy||!rangeValid}>{busy?<><Spinner size="sm"/>{operation?.state==='queued'?t.trimQueued:t.trimProcessing}</>:t.trimSave}</Button>
        </div>
      </div>
      <p className="fms-muted">{t.trimHint} {t.trimMinimum.replace('{seconds}', String(minimum))}</p>
      {error&&<p role="alert" className="fms-error">{error}</p>}
    </section>}
  </>;
}
