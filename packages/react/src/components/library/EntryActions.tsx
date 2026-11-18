import {Button, Dropdown, Label} from '@heroui/react';
import {Delete02Icon, Download01Icon, FolderMoveToIcon, InformationCircleIcon, Link01Icon, MoreHorizontalIcon, PencilEdit02Icon, StarIcon, ViewIcon, Share08Icon} from '@hugeicons/core-free-icons';
import {useState} from 'react';
import type {Entry} from '../../contracts';
import {Icon, type Messages} from '../../ui';

export type EntryActionHandlers = {
  downloadFolders?: boolean;
  inspect?: (entry: Entry) => void;
  share?: (entry: Entry) => void;
  move?: (entry: Entry) => void;
  trash?: (entry: Entry) => void;
  download?: (entry: Entry) => void;
  copy?: (entry: Entry) => void;
  optimize?: (entry: Entry) => void;
};

export function EntryActions({entry, portal, t, handlers, open, toggle, pending, className}: {
  entry: Entry; portal: HTMLDivElement; t: Messages; handlers: EntryActionHandlers;
  open: () => void; toggle: (() => void) | undefined; pending: boolean; className: string;
}) {
  const [visible, setVisible] = useState(false);
  const actions=entryActionItems(entry,t,handlers,open,toggle,pending);

  return <Dropdown isOpen={visible} onOpenChange={setVisible}>
    <Button isIconOnly variant="ghost" size="sm" className={className} aria-label={`${t.moreActions}: ${entry.name}`}>
      <Icon icon={MoreHorizontalIcon} size={18}/>
    </Button>
    {visible && <Dropdown.Popover placement="bottom end" UNSTABLE_portalContainer={portal}>
      <Dropdown.Menu aria-label={t.moreActions} onAction={id => actions.find(action => action.id === id)?.run?.(entry)}>
        {actions.filter(action => action.run).map(action => <Dropdown.Item key={action.id} id={action.id} textValue={action.text}>
          <Icon icon={action.icon} size={18}/><Label>{action.text}</Label>
        </Dropdown.Item>)}
      </Dropdown.Menu>
    </Dropdown.Popover>}
  </Dropdown>;
}

export function entryActionItems(entry:Entry,t:Messages,handlers:EntryActionHandlers,open:(()=>void)|undefined,toggle:(()=>void)|undefined,pending:boolean){
  const editable = !entry.trashedAt && !entry.readOnly;
  const processing = entry.mediaType === 'video' && ['queued', 'processing'].includes(entry.previewState ?? '');
  const favorite = entry.starred ? t.unfavorite : t.favorite;
  const actions = [
    {id: 'share', text: t.share, icon: Share08Icon, run: !entry.trashedAt ? handlers.share : undefined},
    {id: 'copy', text: t.copyLink, icon: Link01Icon, run: !entry.trashedAt && entry.kind === 'file' ? handlers.copy : undefined},
    {id: 'star', text: favorite, icon: StarIcon, run: toggle && !pending ? toggle : undefined},
    {id: 'open', text: t.preview, icon: ViewIcon, run: !processing && !entry.trashedAt && entry.kind === 'file' ? open : undefined},
    ...(handlers.inspect?[{id:'details',text:t.details,icon:InformationCircleIcon,run:handlers.inspect}]:[]),
    {id: 'move', text: t.move, icon: FolderMoveToIcon, run: editable ? handlers.move : undefined},
    {id: 'download', text: t.download, icon: Download01Icon, run: !entry.trashedAt && (entry.kind !== 'folder' || handlers.downloadFolders !== false) ? handlers.download : undefined},
    {id: 'optimize', text: t.optimize, icon: PencilEdit02Icon, run: editable && ['image/jpeg', 'image/png', 'image/webp', 'image/avif'].includes(entry.mimeType ?? '') ? handlers.optimize : undefined},
    {id: 'trash', text: t.moveToTrash, icon: Delete02Icon, run: editable ? handlers.trash : undefined},
  ];
  return actions.filter(action=>action.run);
}
