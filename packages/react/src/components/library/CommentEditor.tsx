import {forwardRef, useImperativeHandle, useRef} from 'react';
import type {CommentRun} from '../../contracts';
export interface CommentEditorHandle {focus():void;insertMention(person:{id:string;principalType:string;name:string|null}):void;replace(runs:CommentRun[]):void}
/** Tiny plain-text editor with atomic identity chips; pasted HTML is never imported. */
export const CommentEditor=forwardRef<CommentEditorHandle,{label:string;placeholder:string;disabled:boolean;listId:string;activeId:string|undefined;onChange:(body:string,runs:CommentRun[],query:string|undefined)=>void;onKeyDown:React.KeyboardEventHandler<HTMLDivElement>}>(({label,placeholder,disabled,listId,activeId,onChange,onKeyDown},ref)=>{
  const root=useRef<HTMLDivElement|null>(null),caret=useRef<Range|null>(null);
  function selectionForEditor(){const tree=root.current?.getRootNode() as (ShadowRoot&{getSelection?:()=>Selection|null})|undefined;return tree?.getSelection?.()??window.getSelection();}
  function remember(){const selection=selectionForEditor();if(selection?.rangeCount&&root.current?.contains(selection.anchorNode))caret.current=selection.getRangeAt(0).cloneRange();}
  function read(){
    const runs:CommentRun[]=[];
    const walk=(node:Node)=>{
      if(node instanceof HTMLElement&&node.dataset.mentionId){runs.push({type:'mention',id:node.dataset.mentionId,principalType:node.dataset.principalType!,name:node.dataset.mentionName??null});return;}
      if(node.nodeType===Node.TEXT_NODE){if(node.textContent)runs.push({type:'text',text:node.textContent});return;}
      if(node instanceof HTMLBRElement){runs.push({type:'text',text:'\n'});return;}
      if(node instanceof HTMLElement&&node!==root.current&&['DIV','P'].includes(node.tagName)&&runs.length)runs.push({type:'text',text:'\n'});
      node.childNodes.forEach(walk);
    };
    root.current?.childNodes.forEach(walk);remember();
    const body=runs.map(r=>r.type==='text'?r.text:'@'+(r.name??'')).join('');
    const range=caret.current;let query:string|undefined;
    if(range?.collapsed&&range.startContainer.nodeType===Node.TEXT_NODE){const before=(range.startContainer.textContent??'').slice(0,range.startOffset);if(!before.endsWith(' '))query=before.match(/(?:^|\s)@([^@\n]{0,60})$/)?.[1];}
    if(query===undefined&&range?.startContainer===root.current&&!body.endsWith(' '))query=body.match(/(?:^|\s)@([^@\n]{0,60})$/)?.[1];
    onChange(body,runs,query);
  }
  function restore(){const selection=selectionForEditor();root.current?.focus();if(caret.current&&root.current?.contains(caret.current.startContainer)){selection?.removeAllRanges();selection?.addRange(caret.current);}else{const range=document.createRange();range.selectNodeContents(root.current!);range.collapse(false);selection?.removeAllRanges();selection?.addRange(range);}return selection;}
  function chip(person:{id:string;principalType:string;name?:string|null}){const span=document.createElement('span');span.contentEditable='false';span.className='fms-comment-mention';span.dataset.mentionId=person.id;span.dataset.principalType=person.principalType;span.dataset.mentionName=person.name??'';span.textContent='@'+(person.name??'Team member');return span;}
  useImperativeHandle(ref,()=>({focus(){root.current?.focus();},replace(runs){if(!root.current)return;root.current.replaceChildren(...runs.map(r=>r.type==='text'?document.createTextNode(r.text):chip(r)));caret.current=null;read();},insertMention(person){
    const selection=restore();if(!selection?.rangeCount)return;const range=selection.getRangeAt(0);
    if(range.startContainer.nodeType===Node.TEXT_NODE){const before=(range.startContainer.textContent??'').slice(0,range.startOffset);const match=before.match(/@([^@\n]{0,60})$/);if(match)range.setStart(range.startContainer,range.startOffset-match[0].length);}
    range.deleteContents();const token=chip(person),space=document.createTextNode(' ');const fragment=document.createDocumentFragment();fragment.append(token,space);range.insertNode(fragment);range.setStart(space,1);range.collapse(true);selection.removeAllRanges();selection.addRange(range);remember();read();
  }}));
  return <div ref={root} role="textbox" aria-label={label} aria-multiline="true" aria-autocomplete="list" aria-expanded={!!activeId} aria-controls={activeId?listId:undefined} aria-activedescendant={activeId} aria-disabled={disabled} contentEditable={!disabled} suppressContentEditableWarning tabIndex={0} className="fms-comment-editable" data-placeholder={placeholder}
    onInput={read} onKeyUp={remember} onMouseUp={remember} onKeyDown={onKeyDown}
    onPaste={event=>{event.preventDefault();const text=event.clipboardData.getData('text/plain').slice(0,5000);const selection=restore();if(!selection?.rangeCount)return;const range=selection.getRangeAt(0);range.deleteContents();const node=document.createTextNode(text);range.insertNode(node);range.setStartAfter(node);range.collapse(true);selection.removeAllRanges();selection.addRange(range);read();}}/>;
});
