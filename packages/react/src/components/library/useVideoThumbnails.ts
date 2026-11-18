import {useEffect, useRef, useState} from 'react';

type Filmstrip = {source: string; count: number; frames: (string | undefined)[]};

/** Sample a bounded set of frames on demand and keep one filmstrip per mounted preview. */
export function useVideoThumbnails(source: string, count: number, enabled: boolean) {
  const [strip, setStrip] = useState<Filmstrip>();
  const cache = useRef<Filmstrip | undefined>(undefined);

  useEffect(() => {
    if (!enabled) return;
    const previous = cache.current;
    const frames = previous?.source === source && previous.count === count
      ? [...previous.frames]
      : Array<string | undefined>(count).fill(undefined);
    if (frames.every(Boolean)) return;

    const video = document.createElement('video');
    video.muted = true;
    video.preload = 'metadata';
    video.crossOrigin = 'use-credentials';
    const canvas = document.createElement('canvas');
    canvas.width = 144;
    canvas.height = 81;
    const context = canvas.getContext('2d', {alpha: false});
    if (!context) return;

    const controller = new AbortController();
    const release = () => {
      if (controller.signal.aborted) return;
      controller.abort();
      video.removeAttribute('src');
      video.load();
      canvas.width = 0;
      canvas.height = 0;
    };
    const deadline = setTimeout(release, 12000);
    const wait = (event: string) => new Promise<void>((resolve, reject) => {
      const cleanup = () => {
        video.removeEventListener(event, done);
        video.removeEventListener('error', fail);
        controller.signal.removeEventListener('abort', fail);
      };
      const done = () => { cleanup(); resolve(); };
      const fail = () => { cleanup(); reject(new Error('Thumbnail unavailable')); };
      video.addEventListener(event, done, {once: true});
      video.addEventListener('error', fail, {once: true});
      controller.signal.addEventListener('abort', fail, {once: true});
    });

    void (async () => {
      const metadata = wait('loadedmetadata');
      video.src = source;
      await metadata;
      if (!Number.isFinite(video.duration) || video.duration <= 0 || !video.videoWidth) return;

      const scale = Math.max(canvas.width / video.videoWidth, canvas.height / video.videoHeight);
      const width = video.videoWidth * scale;
      const height = video.videoHeight * scale;
      for (let index = 0; index < count && !controller.signal.aborted; index++) {
        if (frames[index]) continue;
        const seek = wait('seeked');
        video.currentTime = video.duration * (index + .5) / count;
        await seek;
        if (controller.signal.aborted) break;
        context.drawImage(video, (canvas.width - width) / 2, (canvas.height - height) / 2, width, height);
        frames[index] = canvas.toDataURL('image/webp', .55);
        cache.current = {source, count, frames: [...frames]};
        setStrip(cache.current);
      }
    })().catch(() => {
      // Playback and trimming still work when a source cannot supply canvas frames.
    }).finally(() => {
      clearTimeout(deadline);
      release();
    });

    return () => {
      clearTimeout(deadline);
      release();
    };
  }, [source, count, enabled]);

  return strip?.source === source && strip.count === count ? strip.frames : [];
}
