import {useEffect,useRef,useState} from 'react';

interface SpeechResult {isFinal:boolean;[index:number]:{transcript:string}}
interface SpeechEvent {results:ArrayLike<SpeechResult>}
interface Recognition {
  lang:string;continuous:boolean;interimResults:boolean;maxAlternatives:number;
  onstart:(()=>void)|null;onend:(()=>void)|null;onerror:((event:{error:string})=>void)|null;onresult:((event:SpeechEvent)=>void)|null;
  start:()=>void;stop:()=>void;abort:()=>void;
}
type SpeechWindow=Window&{SpeechRecognition?:new()=>Recognition;webkitSpeechRecognition?:new()=>Recognition};
type State='idle'|'starting'|'listening'|'stopping';
interface Session {recognition:Recognition;stream:MediaStream|null;audio:AudioContext|null;analyser:AnalyserNode|null;source:MediaStreamAudioSourceNode|null;timer:ReturnType<typeof setTimeout>|null;flush:ReturnType<typeof setTimeout>|null;base:string;transcript:string;discard:boolean;settled:boolean;failed:boolean;finishPromise:Promise<string>|null;resolve:((value:string)=>void)|null}
const join=(base:string,speech:string)=>speech.trim()?[base.trimEnd(),speech.trim()].filter(Boolean).join(' ').slice(0,8000):base;

/** Browser speech recognition only. No MediaRecorder, audio blobs, files or Studio uploads. */
export function useDictation(locale:string,onCommit:(value:string)=>void,onError:(message:string)=>void){
  const [state,setState]=useState<State>('idle'),[interim,setInterim]=useState('');
  const current=useRef<Session|null>(null),handlers=useRef({onCommit,onError});handlers.current={onCommit,onError};
  const constructor=()=>{const w=window as SpeechWindow;return w.SpeechRecognition??w.webkitSpeechRecognition;};
  const supported=!!constructor()&&!!navigator.mediaDevices?.getUserMedia&&window.isSecureContext;
  const message=(key:string)=>{const pl=locale==='pl';return key==='not-allowed'||key==='service-not-allowed'?(pl?'Zezwól przeglądarce na dostęp do mikrofonu i dyktowania.':'Allow microphone and speech recognition access in your browser.'):key==='audio-capture'?(pl?'Nie znaleziono dostępnego mikrofonu.':'No microphone is available.'):key==='no-speech'?(pl?'Nie wykryto mowy. Spróbuj ponownie.':'No speech detected. Try again.'):key==='timeout'?(pl?'Mikrofon nie uruchomił się. Spróbuj ponownie.':'The microphone did not start. Try again.'):key==='unsupported'?(pl?'Ta przeglądarka nie udostępnia dyktowania. Użyj przeglądarki z obsługą rozpoznawania mowy.':'This browser does not support dictation. Use a browser with speech recognition.'): (pl?'Usługa rozpoznawania mowy jest niedostępna. Sprawdź połączenie i spróbuj ponownie.':'Speech recognition is unavailable. Check your connection and try again.');};
  function release(s:Session){if(s.timer)clearTimeout(s.timer);if(s.flush)clearTimeout(s.flush);s.stream?.getTracks().forEach(t=>t.stop());s.source?.disconnect();s.analyser?.disconnect();if(s.audio&&s.audio.state!=='closed')void s.audio.close().catch(()=>{});s.recognition.onstart=s.recognition.onend=s.recognition.onerror=s.recognition.onresult=null;try{s.recognition.abort();}catch{};}
  function settle(s:Session,notify=true){if(s.settled)return;s.settled=true;release(s);const value=join(s.base,s.transcript);if(current.current===s){current.current=null;if(notify){setState('idle');setInterim('');if(!s.discard)handlers.current.onCommit(value);}}s.resolve?.(s.discard||s.failed?'':value);}
  function cancel(){const s=current.current;if(s){s.discard=true;settle(s);}}
  function finish():Promise<string>{const s=current.current;if(!s)return Promise.resolve('');if(s.finishPromise)return s.finishPromise;setState('stopping');s.finishPromise=new Promise(resolve=>{s.resolve=resolve;s.flush=setTimeout(()=>{s.failed=true;handlers.current.onError(message('network'));settle(s);},1800);try{s.recognition.stop();}catch{settle(s);}});return s.finishPromise;}
  async function start(base:string){if(current.current)return;if(!supported){handlers.current.onError(message('unsupported'));return;}const C=constructor()!;const recognition=new C();
    const s:Session={recognition,stream:null,audio:null,analyser:null,source:null,timer:null,flush:null,base,transcript:'',discard:false,settled:false,failed:false,finishPromise:null,resolve:null};current.current=s;setState('starting');setInterim('');
    recognition.lang=locale==='pl'?'pl-PL':'en-US';recognition.continuous=true;recognition.interimResults=true;recognition.maxAlternatives=1;
    recognition.onstart=()=>{if(current.current!==s||s.settled)return;if(s.timer)clearTimeout(s.timer);setState('listening');s.timer=setTimeout(()=>{void finish();},120000);};
    recognition.onresult=event=>{if(current.current!==s||s.settled)return;const chunks=Array.from(event.results,result=>result[0]?.transcript??'');s.transcript=chunks.join(' ').slice(0,Math.max(0,8000-s.base.length-1));setInterim(s.transcript);if(join(s.base,s.transcript).length>=8000)void finish();};
    recognition.onend=()=>settle(s);
    recognition.onerror=event=>{if(s.settled)return;s.failed=true;if(event.error!=='aborted')handlers.current.onError(message(event.error));settle(s);};
    s.timer=setTimeout(()=>{if(current.current===s){handlers.current.onError(message('timeout'));settle(s);}},20000);
    try{const stream=await navigator.mediaDevices.getUserMedia({audio:{echoCancellation:true,noiseSuppression:true,autoGainControl:true},video:false});if(current.current!==s||s.settled){stream.getTracks().forEach(t=>t.stop());return;}s.stream=stream;
      // Visualization failure must not prevent speech recognition itself.
      try{s.audio=new AudioContext();s.source=s.audio.createMediaStreamSource(stream);s.analyser=s.audio.createAnalyser();s.analyser.fftSize=256;s.source.connect(s.analyser);void s.audio.resume().catch(()=>{});}catch{}
      recognition.start();
    }catch(error){if(s.settled)return;s.failed=true;handlers.current.onError(message(error instanceof DOMException&&error.name==='NotAllowedError'?'not-allowed':error instanceof DOMException&&error.name==='NotFoundError'?'audio-capture':'network'));settle(s);}
  }
  useEffect(()=>{const hidden=()=>{if(document.hidden){const s=current.current;if(s)settle(s);}};document.addEventListener('visibilitychange',hidden);return()=>{document.removeEventListener('visibilitychange',hidden);const s=current.current;if(s){s.discard=true;settle(s,false);}};},[]);
  return {state,interim,supported,start,cancel,finish,analyser:()=>current.current?.analyser??null};
}
