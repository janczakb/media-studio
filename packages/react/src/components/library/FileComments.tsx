import {useStudioTimeZone,useStudioLocale,useDateText} from '../layout/TimePreferences';
import {Button, ButtonGroup, Dropdown, InputGroup, Modal, Spinner, TextField, Tooltip} from '@heroui/react';
import {Tick01Icon, RefreshIcon, MoreHorizontalIcon, ArrowUp02Icon, FilterHorizontalIcon} from '@hugeicons/core-free-icons';
import {useEffect, useId, useRef, useState} from 'react';
import {createPortal} from 'react-dom';
import {VirtualCommentFeed} from './VirtualCommentFeed';
import {CommentEditor, type CommentEditorHandle} from './CommentEditor';
import type {CommentRun, CommentPage, CommentTransport, FileComment} from '../../contracts';
import {randomId} from '../../random-id';
import {errorText, Icon, type Messages} from '../../ui';

type Person={id:string;principalType:string;memberId:string;name:string|null;avatarUrl:string|null};


export function FileComments({workspace,entry,transport,t,portal,controls}:{workspace:string;entry:string;transport:CommentTransport;t:Messages;portal:HTMLDivElement;controls:HTMLDivElement|null}) {
  const mentionListId=useId();
  const textInput=useRef<CommentEditorHandle|null>(null);
  const zone=useStudioTimeZone(),locale=useStudioLocale(),dateText=useDateText();
  const commentTime=new Intl.DateTimeFormat(locale,{hour:'numeric',minute:'2-digit',timeZone:zone});
  const [activePerson,setActivePerson]=useState(0);
  const [dismissedQuery,setDismissedQuery]=useState<string|null>(null);
  const [overlayRoot,setOverlayRoot]=useState<HTMLDivElement|null>(null);
  const [page,setPage]=useState<CommentPage|null>(null),[filter,setFilter]=useState('all');
  const [runs,setRuns]=useState<CommentRun[]>([]);
  const [match,setMatch]=useState<string|undefined>();
  const [body,setBody]=useState(''),[mentions,setMentions]=useState<Person[]>([]),[candidates,setCandidates]=useState<Person[]>([]);
  const [editing,setEditing]=useState<FileComment|null>(null),[removing,setRemoving]=useState<FileComment|null>(null);
  const [busy,setBusy]=useState(false),[error,setError]=useState(''),[disabled,setDisabled]=useState(false),[generation,refresh]=useState(0);
  const operation=useRef<AbortController|null>(null),draftKey=useRef(randomId());
  useEffect(()=>()=>operation.current?.abort(),[]);
  useEffect(()=>{
    if(match===undefined){setCandidates([]);return;}
    const c=new AbortController();const timer=setTimeout(()=>{void transport.mentions(workspace,entry,match,c.signal).then(v=>{if(!c.signal.aborted){setCandidates(v.items);setActivePerson(0);}}).catch(()=>{if(!c.signal.aborted)setCandidates([]);});},180);return()=>{clearTimeout(timer);c.abort();};
  },[match,workspace,entry,transport]);
  const mentionOpen=!!candidates.length&&mentions.length<10&&match!==undefined&&dismissedQuery!==body;
  function choosePerson(person:Person){
    textInput.current?.insertMention(person);
    setMentions(value=>value.some(m=>m.id===person.id&&m.principalType===person.principalType)?value:[...value,person]);
    setCandidates([]);
  }
  async function mutate(action:(signal:AbortSignal)=>Promise<unknown>,reset=false){
    if(operation.current)return;const c=new AbortController();operation.current=c;setBusy(true);setError('');
    try{await action(c.signal);if(!c.signal.aborted){if(reset){setBody('');setRuns([]);textInput.current?.replace([]);setMentions([]);setEditing(null);draftKey.current=randomId();}setRemoving(null);refresh(v=>v+1);}}
    catch(e){if(!c.signal.aborted)setError(errorText(e));}finally{if(operation.current===c)operation.current=null;if(!c.signal.aborted)setBusy(false);}
  }
  if(disabled)return null;
  return <section className="fms-comments" aria-label={t.comments}>
    {controls&&createPortal(<Tooltip delay={300}>
      <Dropdown>
        <Button size="sm" isIconOnly variant={filter==='all'?'secondary':'primary'} aria-label={t.commentsFilter} className="fms-comment-filter"><Icon icon={FilterHorizontalIcon} size={18}/></Button>
        <Dropdown.Popover UNSTABLE_portalContainer={overlayRoot??portal}>
          <Dropdown.Menu aria-label={t.commentsFilter} selectionMode="single" disallowEmptySelection selectedKeys={[filter]} onSelectionChange={keys=>{if(keys!=='all')setFilter(String([...keys][0]));}}>
            <Dropdown.Item id="all">{t.commentsAll}</Dropdown.Item>
            <Dropdown.Item id="open">{t.commentsOpen}</Dropdown.Item>
            <Dropdown.Item id="resolved">{t.commentsResolved}</Dropdown.Item>
          </Dropdown.Menu>
        </Dropdown.Popover>
      </Dropdown>
      <Tooltip.Content UNSTABLE_portalContainer={overlayRoot??portal}>{t.commentsFilter}</Tooltip.Content>
    </Tooltip>,controls)}
    {error&&<p role="alert" className="fms-error">{error}</p>}
    <VirtualCommentFeed key={`${workspace}:${entry}:${filter}:${generation}`} load={(cursor,signal)=>transport.list(workspace,entry,filter,cursor,signal)} t={t} onPage={setPage} onDenied={()=>setDisabled(true)} render={c=><article className="fms-comment" data-resolved={c.resolved} key={c.id}>
      <header>
        <div className="fms-comment-avatar">{c.author.avatarUrl?<img src={c.author.avatarUrl} alt="" loading="lazy"/>:(c.author.name??'?').slice(0,1).toLocaleUpperCase()}</div>
        <strong title={c.author.name??t.commentMember}>{c.author.name??t.commentMember}</strong>
        <time dateTime={c.createdAt} title={dateText(c.createdAt)}>{commentTime.format(new Date(c.createdAt))}</time>
        {(c.canResolve||c.canEdit)&&<ButtonGroup size="sm" variant="ghost" className="fms-comment-actions" aria-label={t.commentActions}>
          {c.canResolve&&<Tooltip>
            <Button size="sm" variant="ghost" isIconOnly aria-label={c.resolved?t.commentReopen:t.commentResolve} data-active={c.resolved} isDisabled={busy} onPress={()=>void mutate(s=>transport.update(workspace,entry,c.id,{revision:c.revision,resolved:!c.resolved},s))}>
              <Icon icon={c.resolved?RefreshIcon:Tick01Icon} size={18}/>
            </Button>
            <Tooltip.Content UNSTABLE_portalContainer={overlayRoot??portal}>{c.resolved?t.commentReopen:t.commentResolve}</Tooltip.Content>
          </Tooltip>}
          {c.canEdit&&<Dropdown>
            <Button size="sm" variant="ghost" isIconOnly aria-label={t.commentActions}><Icon icon={MoreHorizontalIcon} size={18}/></Button>
            <Dropdown.Popover placement="bottom end" UNSTABLE_portalContainer={overlayRoot??portal}>
              <Dropdown.Menu aria-label={t.commentActions} onAction={key=>{
                if(key==='edit'){
                  setEditing(c);
                  setBody(c.body);
                  textInput.current?.replace(c.document??[{type:'text',text:c.body}]);
                  setMentions([]);
                }
                if(key==='delete')setRemoving(c);
              }}>
                <Dropdown.Item id="edit">{t.commentEdit}</Dropdown.Item>
                <Dropdown.Item id="delete">{t.commentRemove}</Dropdown.Item>
              </Dropdown.Menu>
            </Dropdown.Popover>
          </Dropdown>}
        </ButtonGroup>}
      </header>
      <p>{c.document?c.document.map((run,i)=>run.type==='text'?run.text:<span key={i} className="fms-comment-mention">@{run.name??t.commentMember}</span>):c.body}</p>
    </article>}/>
    {page?.canCreate&&<form className="fms-comment-composer" onSubmit={e=>{e.preventDefault();if(!body.trim())return;void mutate(s=>editing?transport.update(workspace,entry,editing.id,{revision:editing.revision,body,document:runs,mentions:mentions.map(m=>m.memberId)},s):transport.create(workspace,entry,{body,document:runs,mentions:mentions.map(m=>m.memberId),key:draftKey.current},s),true);}}>
      <TextField fullWidth aria-label={editing?t.commentEditing:t.commentAdd} isDisabled={busy}>
        <InputGroup fullWidth className="fms-comment-input">
          <CommentEditor ref={textInput} label={editing?t.commentEditing:t.commentAdd} placeholder={t.commentAdd} disabled={busy} listId={mentionListId} activeId={mentionOpen?`${mentionListId}-${activePerson}`:undefined} onChange={(text,document,query)=>{setBody(text);setRuns(document);setMatch(query);}} onKeyDown={event=>{
            if(!mentionOpen)return;
            if(event.key==='ArrowDown'||event.key==='ArrowUp'){event.preventDefault();setActivePerson(i=>(i+(event.key==='ArrowDown'?1:-1)+candidates.length)%candidates.length);}
            if(event.key==='Enter'&&!event.shiftKey&&!event.nativeEvent.isComposing){event.preventDefault();const person=candidates[activePerson];if(person)choosePerson(person);}
            if(event.key==='Escape'){event.preventDefault();event.stopPropagation();setDismissedQuery(body);}
          }}/>

          <InputGroup.Suffix className="fms-comment-input-actions">
            <Tooltip delay={300}><Button isIconOnly size="sm" type="submit" aria-label={editing?t.save:t.commentSend} isDisabled={busy||!body.trim()}>{busy?<Spinner size="sm"/>:<Icon icon={ArrowUp02Icon} size={16}/>}</Button><Tooltip.Content UNSTABLE_portalContainer={overlayRoot??portal}>{editing?t.save:t.commentSend}</Tooltip.Content></Tooltip>
          </InputGroup.Suffix>
        </InputGroup>
      </TextField>
      {mentionOpen&&<div className="fms-mention-results">
        <div className="fms-mention-heading">{t.commentMembers}</div>
        <div id={mentionListId} role="listbox" aria-label={t.commentMention}>
          {candidates.map((person,index)=><div key={person.id} id={`${mentionListId}-${index}`} role="option" aria-selected={index===activePerson} className="fms-mention-row"><Button variant="ghost" className="fms-mention-option" onHoverStart={()=>setActivePerson(index)} onPress={()=>choosePerson(person)}>
            <span className="fms-mention-avatar">{person.avatarUrl?<img src={person.avatarUrl} alt=""/>:(person.name??'?').slice(0,1).toUpperCase()}</span>
            <span className="fms-mention-name">{person.name??t.commentMember}</span>
          </Button></div>)}
        </div>
      </div>}
      {editing&&<Button size="sm" variant="ghost" onPress={()=>{setEditing(null);setBody('');setRuns([]);textInput.current?.replace([]);setMentions([]);}}>{t.cancel}</Button>}
    </form>}
    {removing&&<Modal><Modal.Backdrop isOpen onOpenChange={open=>{if(!open&&!busy)setRemoving(null);}} UNSTABLE_portalContainer={overlayRoot??portal}><Modal.Container size="sm" placement="center"><Modal.Dialog aria-label={t.commentDelete}><Modal.CloseTrigger/><Modal.Header><Modal.Heading>{t.commentDelete}</Modal.Heading></Modal.Header><Modal.Body>{t.commentDeleteHint}</Modal.Body><Modal.Footer><Button variant="tertiary" isDisabled={busy} onPress={()=>setRemoving(null)}>{t.cancel}</Button><Button variant="danger" isDisabled={busy} onPress={()=>void mutate(s=>transport.update(workspace,entry,removing.id,{revision:removing.revision,delete:true},s))}>{t.commentRemove}</Button></Modal.Footer></Modal.Dialog></Modal.Container></Modal.Backdrop></Modal>}
    <div ref={setOverlayRoot} className="fms-comments-overlays"/>
  </section>;
}
