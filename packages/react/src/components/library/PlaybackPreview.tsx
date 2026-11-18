import {createElement, useEffect, useRef, useState,type CSSProperties} from 'react';
import {useIsPresent} from 'motion/react';
import type {Entry} from '../../contracts';
import type {Messages} from '../../ui';
import {useVideoThumbnails} from './useVideoThumbnails';

let videoPlayer: Promise<unknown> | undefined;

export function loadVideoPlayer() {
  videoPlayer ??= import(new URL('./video-player.js', import.meta.url).href).catch(error => {
    videoPlayer = undefined;
    throw error;
  });
  return videoPlayer;
}

export function PlaybackPreview({entry, url, t, skinVariant = 'default', active = true, onDimensions}: {entry: Entry; url: string; t: Messages; skinVariant?: 'default' | 'neutral'; active?: boolean; onDimensions?: (width: number, height: number, duration: number) => void}) {
  const isVideo = entry.mediaType === 'video';
  const [enhanced, setEnhanced] = useState(false);
  const [engineSettled,setEngineSettled]=useState(false);
  const [frameReady,setFrameReady]=useState(false);
  const [aspect,setAspect]=useState(entry.width&&entry.height?entry.width/entry.height:16/9);
  useEffect(() => {
    if (!active) return;
    let disposed = false;
    void loadVideoPlayer().then(() => {if (!disposed) setEnhanced(true);}).catch(() => {
      // Native playback remains available when the optional player cannot load.
    }).finally(()=>{if(!disposed)setEngineSettled(true);});
    return () => { disposed = true; };
  }, [active]);

  const player = useRef<HTMLMediaElement | null>(null);
  const present = useIsPresent();
  useEffect(() => {
    if (!present || !active) player.current?.pause();
  }, [present, active]);
  const skin = useRef<HTMLElement|null>(null);
  useEffect(()=>{
    if(!isVideo||!enhanced)return;
    const surface=skin.current?.shadowRoot?.querySelector<HTMLElement>('.media-skin');
    if(!surface)return;
    const title=surface.querySelector<HTMLElement>('.media-title');
    if(title)title.hidden=true;
  },[enhanced,isVideo]);
  const [failed, setFailed] = useState(false);
  const [duration, setDuration] = useState(0);
  const [thumbnailIntent, setThumbnailIntent] = useState(false);
  const thumbnailCount = 8;
  const frames = useVideoThumbnails(url, thumbnailCount, isVideo && enhanced && thumbnailIntent && !failed && present && active);

  useEffect(() => {
    if (!isVideo || !enhanced || failed) return;
    const slider = skin.current?.shadowRoot?.querySelector('media-time-slider');
    if (!slider) return;
    const requestThumbnails = () => setThumbnailIntent(true);
    slider.addEventListener('pointerenter', requestThumbnails, {once: true});
    slider.addEventListener('focusin', requestThumbnails, {once: true});
    return () => {
      slider.removeEventListener('pointerenter', requestThumbnails);
      slider.removeEventListener('focusin', requestThumbnails);
    };
  }, [enhanced, failed, isVideo]);

  useEffect(() => {
    if (!isVideo) return;
    const thumbnail = skin.current?.shadowRoot?.querySelector<HTMLElement & {
      thumbnails?: {url: string; startTime: number; endTime: number}[];
    }>('media-slider-thumbnail');
    if (!thumbnail) return;
    thumbnail.thumbnails = duration > 0 ? frames.flatMap((url, index) => url ? [{
      url,
      startTime: duration * index / thumbnailCount,
      endTime: duration * (index + 1) / thumbnailCount,
    }] : []) : [];
  }, [enhanced, frames, duration, isVideo]);

  useEffect(() => {
    const media = player.current;
    if (!media) return;

    setFailed(false);setFrameReady(false);setDuration(0);setThumbnailIntent(false);
    media.src = url;

    return () => {
      media.pause();
      media.removeAttribute('src');
      media.load();
    };
  }, [url]);

  const video = isVideo && <video ref={node => { player.current = node; }} poster={entry.thumbnailUrl ?? undefined}
    aria-label={entry.name} controls={!enhanced} playsInline preload="metadata" onLoadedMetadata={event=>{const media=event.currentTarget;if(Number.isFinite(media.duration))setDuration(media.duration);if (media.videoWidth && media.videoHeight) {
      setAspect(media.videoWidth / media.videoHeight);
      onDimensions?.(media.videoWidth, media.videoHeight, Number.isFinite(media.duration) ? media.duration : 0);
    }}} onLoadedData={()=>setFrameReady(true)} onCanPlay={()=>setFrameReady(true)} onError={() => setFailed(true)}/>;

  const loading=isVideo&&!failed&&(!engineSettled||!frameReady);
  return <div className={'fms-playback-preview' + (isVideo ? ' is-video' : '')} inert={!present||!active} data-loading={loading||undefined} aria-busy={loading||undefined} style={isVideo?{'--fms-video-aspect':aspect} as CSSProperties:undefined}>
    {isVideo
      ? createElement('video-player', {className: 'fms-video-player', 'aria-label': entry.name}, createElement(skinVariant === 'neutral' ? 'video-neutral-skin' : 'video-skin', {className:'fms-video-skin', ref:(node:HTMLElement|null)=>{skin.current=node;}}, video))
      : createElement('audio-player', {className:'fms-audio-player', 'aria-label':entry.name},
          createElement(skinVariant === 'neutral' ? 'audio-neutral-skin' : 'audio-skin', {className:'fms-audio-skin'},
            <audio ref={node => { player.current = node; }} aria-label={entry.name}
              controls={!enhanced} preload="metadata" onError={() => setFailed(true)}/>))}
    {loading&&<div className="fms-video-loading" role="status" aria-label={t.loading}>
      {entry.thumbnailUrl?<img src={entry.thumbnailUrl} alt="" className="fms-video-loading-poster"/>:<div className="fms-video-skeleton"/>}
    </div>}
    {failed && <p role="alert" className="fms-playback-error">{t.previewFailed}</p>}
  </div>;
}
