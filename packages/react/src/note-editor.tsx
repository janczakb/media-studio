import {createRoot} from 'react-dom/client';
import {SimpleEditor} from './vendor/tiptap/components/tiptap-templates/simple/simple-editor';
import type {Editor,JSONContent} from '@tiptap/core';
let editor:Editor|null=null;
let channel='';
let initialized=false;
window.addEventListener('message',event=>{
 if(event.source!==window.parent||event.origin!==window.parent.location.origin)return;
 const message=event.data;
 if(!message||typeof message!=='object')return;
 if(message.type==='flex-note-init'&&!initialized&&typeof message.channel==='string'){
  initialized=true;channel=message.channel;
  document.documentElement.classList.toggle('dark',message.dark===true);
  document.body.style.backgroundColor=message.background;
  createRoot(document.getElementById('note-root')!).render(<SimpleEditor initialContent={message.document as JSONContent} editable={message.editable!==false} onReady={value=>{editor=value;window.parent.postMessage({type:'flex-note-ready',channel},window.parent.location.origin);}}/>);
 }
 if(!channel||message.channel!==channel)return;
 if(message.type==='flex-note-background')document.body.style.backgroundColor=message.background;
 if(message.type==='flex-note-snapshot'&&editor)window.parent.postMessage({type:'flex-note-document',channel,request:message.request,document:editor.getJSON()},window.parent.location.origin);
});
window.parent.postMessage({type:'flex-note-load'},window.parent.location.origin);
window.addEventListener('pagehide',()=>editor?.destroy(),{once:true});
