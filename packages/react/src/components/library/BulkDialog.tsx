import {Button,Modal,Spinner} from '@heroui/react';
import {useEffect,useRef,useState} from 'react';
import type {BulkEntry,BulkEntryRequest,Entry,StudioTransport} from '../../contracts';
import {errorText,type Messages} from '../../ui';
import {DestinationDialog} from './MoveDialog';

export function BulkDialog({operation,items,workspace,transport,portal,t,onClose,onComplete,moveSubmit,onPending}:{
  operation:BulkEntryRequest['operation'];
  items:BulkEntry[];
  workspace:string;
  transport:StudioTransport;
  portal:HTMLDivElement;
  t:Messages;
  onClose:()=>void;
  onComplete:(items:Entry[],parent:string|null)=>void;
  onPending?:(ids:string[],pending:boolean)=>void;
  moveSubmit?:(parent:string|null,signal:AbortSignal)=>Promise<void>;
}) {
  const submit = async(parent:string|null,signal:AbortSignal)=>{
    if(!transport.bulk)return;
    const request:BulkEntryRequest = operation==='move'?{operation,items,parent}:{operation,items};
    if(operation==='trash')onPending?.(items.map(item=>item.id),true);
    try {
      const result = await transport.bulk(workspace,request,signal);
      if(!signal.aborted)onComplete(result.items,parent);
    } finally {if(operation==='trash')onPending?.(items.map(item=>item.id),false);}
  };

  if(operation==='move')return <DestinationDialog workspace={workspace} transport={transport} portal={portal} t={t}
    excludedIds={items.map(item=>item.id)} onClose={onClose} submit={moveSubmit??submit}/>;
  return <ConfirmBulkDialog operation={operation} portal={portal} t={t} onClose={onClose} submit={signal=>submit(null,signal)}/>;
}

function ConfirmBulkDialog({operation,portal,t,onClose,submit}:{
  operation:'trash'|'restore';
  portal:HTMLDivElement;
  t:Messages;
  onClose:()=>void;
  submit:(signal:AbortSignal)=>Promise<void>;
}) {
  const [busy,setBusy] = useState(false);
  const [error,setError] = useState('');
  const mutation = useRef<AbortController|null>(null);
  useEffect(()=>()=>mutation.current?.abort(),[]);
  const trash = operation==='trash';
  return <Modal><Modal.Backdrop isOpen onOpenChange={open=>{if(!open&&!mutation.current)onClose();}} UNSTABLE_portalContainer={portal}>
    <Modal.Container size="sm" placement="center"><Modal.Dialog aria-label={trash?t.bulkTrashTitle:t.bulkRestoreTitle}>
      <Modal.CloseTrigger isDisabled={busy}/>
      <Modal.Header><Modal.Heading>{trash?t.bulkTrashTitle:t.bulkRestoreTitle}</Modal.Heading></Modal.Header>
      <Modal.Body>
        <p>{trash?t.deleteHint:t.bulkRestoreHint}</p>
        {error&&<p className="fms-error" role="alert">{error}</p>}
      </Modal.Body>
      <Modal.Footer>
        <Button variant="tertiary" isDisabled={busy} onPress={onClose}>{t.cancel}</Button>
        <Button variant={trash?'danger':'primary'} isDisabled={busy} onPress={()=>{
          if(mutation.current)return;
          const controller = new AbortController();
          mutation.current = controller;
          setBusy(true);
          setError('');
          void submit(controller.signal).then(()=>{
            if(!controller.signal.aborted)onClose();
          }).catch(error=>{
            if(!controller.signal.aborted)setError(errorText(error));
          }).finally(()=>{
            mutation.current = null;
            if(!controller.signal.aborted)setBusy(false);
          });
        }}>{busy?<Spinner size="sm"/>:trash?t.moveToTrash:t.restore}</Button>
      </Modal.Footer>
    </Modal.Dialog></Modal.Container>
  </Modal.Backdrop></Modal>;
}
