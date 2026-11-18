import {AudioWave01Icon} from '@hugeicons/core-free-icons';
import {memo} from 'react';
import {Icon} from '../../ui';

export const AudioArtwork=memo(function AudioArtwork({samples,compact}:{samples?:string|null|undefined;compact:boolean}) {
  const hasSamples=!!samples&&/^[0-9a-f]{32}$/.test(samples);
  // A neutral illustration fills cards until measured peaks are available.
  const waveform=compact?null:hasSamples?samples:'3579645797641358b777776457777764';
  const bars=waveform?Array.from(waveform,(sample,index)=>{
    const x=4+index*5;
    const amplitude=Math.max(.35,Number.parseInt(sample,16)*1.15);
    return `M${x} ${22-amplitude}v${2*amplitude}`;
  }).join(' '):null;

  return <div className="fms-audio-artwork" aria-hidden="true">
    {bars
      ? <svg className="fms-audio-waveform" viewBox="0 0 163 44" fill="none"><path d={bars} stroke="currentColor" strokeWidth="2.8" strokeLinecap="round"/></svg>
      : <Icon icon={AudioWave01Icon} size={18}/>}
  </div>;
});
