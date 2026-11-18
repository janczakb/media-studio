import {createElement,useEffect,useState,type RefObject} from 'react';
import type {VideoPlayerElement} from '@videojs/html/video';
import {loadVideoPlayer} from './PlaybackPreview';

/** The editor uses the same Video.js skin as normal video preview. */
export function TrimPlayer({showControls,source,name,media,controller,onDuration,onTime,onPlaying,onError}:{showControls:boolean;source:string;name:string;media:RefObject<HTMLVideoElement|null>;controller:RefObject<VideoPlayerElement|null>;onDuration:(duration:number)=>void;onTime:(time:number)=>void;onPlaying:(playing:boolean)=>void;onError:()=>void}){
  const [ready,setReady]=useState(false);
  useEffect(()=>{let disposed=false;void loadVideoPlayer().then(()=>{if(!disposed)setReady(true);}).catch(onError);return()=>{disposed=true;};},[]);
  useEffect(() => {
    const video = media.current;
    return () => {
      if (!video) return;
      video.pause();
      if (!video.isConnected) {
        video.removeAttribute('src');
        video.load();
      }
    };
  }, [ready, source, media]);
  if(!ready)return null;
  return createElement('video-player',{className:'fms-trim-player',ref:(node:VideoPlayerElement|null)=>{controller.current=node;},'aria-label':name},
    createElement('video-skin', {ref: (node: HTMLElement | null) => {
      const skin = node?.shadowRoot;
      const title = skin?.querySelector<HTMLElement>('.media-title');
      if (title) title.hidden = true;
      // Visibility toggling is transient. Remove the stock controls from the
      // trim layout and keyboard navigation while retaining the Video.js skin.
      const controls = skin?.querySelector<HTMLElement>('media-controls');
      if (controls) controls.style.display = showControls ? '' : 'none';
    }},<video ref={node=>{media.current=node;}} src={source} playsInline preload="metadata" aria-label={name}
      onPlay={()=>onPlaying(true)} onPause={()=>onPlaying(false)} onEnded={()=>onPlaying(false)}
      onError={onError} onLoadedMetadata={event=>{
        const video = event.currentTarget;
        if (video.videoWidth && video.videoHeight) {
          controller.current?.style.setProperty('--fms-video-aspect', String(video.videoWidth / video.videoHeight));
        }
        onDuration(video.duration);
      }} onTimeUpdate={event=>onTime(event.currentTarget.currentTime)}/>));
}
