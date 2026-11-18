import {Button,Checkbox} from '@heroui/react';
import {motion} from 'motion/react';
import {Video01Icon} from '@hugeicons/core-free-icons';
import {memo,useRef} from 'react';
import type {Entry} from '../../contracts';
import {Icon,type Messages} from '../../ui';
import {FileTypeIcon} from '../library/FileTypeIcon';
import {EntryActions, type EntryActionHandlers} from '../library/EntryActions';
import {type ToggleFavorite} from '../library/FavoriteButton';

export const PhotoCard=memo(function PhotoCard({entry,top,column,cell,aspect,open,t,density,reducedMotion,selected,canSelect,selectionFull,onSelect,toggleFavorite,favoritePending,actions,portal,canMove}:{
  entry:Entry;density:number;reducedMotion:boolean;top:number;column:number;cell:number;aspect:'square'|'original';
  open:(entry:Entry,source:HTMLImageElement|null)=>void;t:Messages;
  canMove:boolean;
  selected:boolean;canSelect:boolean;selectionFull:boolean;onSelect:(entry:Entry,value:boolean)=>void;
  actions: EntryActionHandlers; portal: HTMLDivElement;
  toggleFavorite:ToggleFavorite|undefined;favoritePending:boolean;
}){
  const processing = entry.mediaType === 'video' && ['queued', 'processing'].includes(entry.previewState ?? '');
  const image=useRef<HTMLImageElement>(null);
  return <motion.div layout={!reducedMotion} layoutDependency={density} transition={{layout:{type:"spring",visualDuration:.34,bounce:.06}}} data-move-entry={canMove?entry.id:undefined} data-photo-id={entry.id} data-select-entry={canSelect?entry.id:undefined} data-select-revision={entry.revision} className={`fms-photo-cell is-${aspect}${selected?' is-selected':''}`} style={{top,insetInlineStart:column*(cell+4),width:cell,height:cell}}>
    <Button variant="ghost" className="fms-photo-card" isDisabled={processing} aria-label={`${t.preview}: ${entry.name}`} onPress={()=>open(entry,image.current)}>
      {entry.thumbnailUrl?<img ref={image} src={entry.thumbnailUrl} alt={entry.name} loading="lazy" decoding="async"/>:<FileTypeIcon entry={entry} size={32}/>}
      {processing && <span className="fms-photo-processing">{t.processingVideo}</span>}
      {entry.mediaType==='video'&&<span className="fms-photo-video"><Icon icon={Video01Icon} size={16}/></span>}
    </Button>
    {canSelect&&<Checkbox className="fms-photo-checkbox" aria-label={`${t.selectItem}: ${entry.name}`} isSelected={selected} isDisabled={selectionFull&&!selected} onChange={value=>onSelect(entry,value)}><Checkbox.Content><Checkbox.Control><Checkbox.Indicator/></Checkbox.Control></Checkbox.Content></Checkbox>}
    <EntryActions className="fms-card-actions" entry={entry} portal={portal} t={t} handlers={actions}
      open={()=>open(entry,image.current)} toggle={toggleFavorite ? () => toggleFavorite(entry) : undefined} pending={favoritePending}/>
  </motion.div>;
});
