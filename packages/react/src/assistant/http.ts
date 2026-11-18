import type {AssistantEvent,AssistantTransport} from './contracts';
export function createAssistantTransport(request:<T>(path:string,signal:AbortSignal,body?:BodyInit,method?:'PATCH'|'POST'|'PUT'|'DELETE')=>Promise<T>,options:{baseUrl:string;credentials?:RequestCredentials}):AssistantTransport {
  const path=(workspace:string,conversation?:string)=>`/workspaces/${encodeURIComponent(workspace)}/assistant/conversations${conversation?'/'+encodeURIComponent(conversation):''}`;
  return {
    undo:(w,id,s)=>request(path(w).replace(/conversations$/,'actions/')+encodeURIComponent(id)+'/undo',s,undefined,'POST'),
    rename:(w,id,title,revision,s)=>request(path(w,id),s,JSON.stringify({title,revision}),'PATCH'),
    remove:(w,id,revision,s)=>request(path(w,id),s,JSON.stringify({revision}),'DELETE'),
    restore:(w,id,revision,s)=>request(path(w,id)+'/restore',s,JSON.stringify({revision})),
    discardAttachment:(w,id,s)=>request(path(w).replace(/conversations$/,'attachments/')+encodeURIComponent(id),s,undefined,'DELETE'),
    upload:(w,file,s)=>{const body=new FormData();body.append('file',file);return request(path(w).replace(/conversations$/,'attachments'),s,body);},
    profile:(w,s)=>request(path(w).replace(/conversations$/,'profile'),s),
    saveProfile:(w,p,s)=>request(path(w).replace(/conversations$/,'profile'),s,JSON.stringify(p),'PUT'),
    list:(w,c,s,search)=>request(path(w)+'?'+new URLSearchParams({...(c?{cursor:c}:{}),...(search?{search}:{})}),s),
    create:(w,s)=>request(path(w),s,undefined,'POST'),
    show:(w,c,s,page)=>request(path(w,c)+(page?'?'+new URLSearchParams(page):''),s),
    send:(w,c,input,s)=>request(path(w,c)+'/messages',s,JSON.stringify(input)),
    cancel:(w,c,s)=>request(path(w,c)+'/cancel',s,undefined,'POST'),
    events:async(w,c,r,after,onEvent,signal)=>{
      const response=await fetch(options.baseUrl.replace(/\/$/,'')+path(w,c)+`/runs/${encodeURIComponent(r)}/events?after=${after}`,{signal,credentials:options.credentials??'same-origin',headers:{Accept:'text/event-stream'}});
      if(!response.ok||!response.body)throw new Error('The response stream is unavailable.');
      const reader=response.body.getReader(),decoder=new TextDecoder();let pending='';
      try {
        while(true){const part=await reader.read();if(part.done)break;pending+=decoder.decode(part.value,{stream:true}).replace(/\r\n/g,'\n');
          if(pending.length>131072)throw new Error('Invalid response stream.');
          let boundary:number;
          while((boundary=pending.indexOf('\n\n'))>=0){const block=pending.slice(0,boundary);pending=pending.slice(boundary+2);let id=0,type='',data='';
            for(const line of block.split('\n')){if(line.startsWith('id:'))id=Number(line.slice(3));if(line.startsWith('event:'))type=line.slice(6).trim();if(line.startsWith('data:'))data+=line.slice(5).trimStart();}
            if(id>after&&Number.isSafeInteger(id)&&data){const event={id,type,data:JSON.parse(data)} as AssistantEvent;onEvent(event);if(type==='done'){await reader.cancel();return;}}
          }
        }
      } finally {reader.releaseLock();}
    },
  };
}
