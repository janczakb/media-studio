import {SidebarFolderTree} from './SidebarFolderTree';
import type {FolderSummary,FolderTreeClient} from '../../folders/FolderTreeClient';
import {Button,ScrollShadow} from '@heroui/react';
import {FolderLockIcon,Folder01Icon,Image01Icon,StarIcon,Clock01Icon,Delete02Icon,TagsIcon} from '@hugeicons/core-free-icons';
import type {StudioTransport,Workspace} from '../../contracts';
import type {StudioView} from '../../studio-location';
import {Icon,type Messages} from '../../ui';
import type {NavigationSection} from './NavRail';

export function ContextSidebar({view,folderTree,folderPath,collapsedPath,selectedFolder,preferenceKey,onFolder,canApplicationFiles,transport,management,t,onView}: {
  collapsedPath?:boolean;folderTree:FolderTreeClient|null;folderPath:readonly FolderSummary[];selectedFolder:string|null;preferenceKey:string;onFolder:(folder:FolderSummary)=>void;
  section:NavigationSection;view:StudioView;canApplicationFiles:boolean;
  transport:StudioTransport;management:StudioTransport['management'];workspaces:Workspace[];
  activeId:string|undefined;canCreate:boolean;locale:string;portalContainer:HTMLDivElement;t:Messages;
  onView:(view:StudioView)=>void;onWorkspace:(id:string)=>void;
  onCreate:()=>void;onSaved:(workspace:Workspace)=>void;
}) {
  return <>
    <div className="fms-sidebar-heading">{t.library}</div>
    <nav className="fms-context-nav" aria-label={t.library}>
      <ScrollShadow hideScrollBar size={16} className="fms-sidebar-content">
        {folderTree
          ? <SidebarFolderTree key={folderTree.workspace} client={folderTree} refreshOnFocus={!transport.folders?.changes} path={folderPath} collapsed={collapsedPath ?? false} active={view==='files'} selected={selectedFolder} preferenceKey={preferenceKey} t={t} onRoot={()=>onView('files')} onFolder={onFolder}/>
          : <Button variant="ghost" className={`fms-nav${view==='files'?' is-active':''}`} aria-label={t.files} aria-current={view==='files'?'page':'false'} onPress={()=>onView('files')}><Icon icon={Folder01Icon}/><span>{t.files}</span></Button>}
      </ScrollShadow>
      <ScrollShadow hideScrollBar size={16} className="fms-sidebar-footer">
        {([
          ['application',FolderLockIcon,canApplicationFiles],
          ['photos',Image01Icon,true],
          ['starred',StarIcon,!!transport.star],
          ['recent',Clock01Icon,!!transport.visit],
          ['trash',Delete02Icon,true],
          ['tags',TagsIcon,!!management?.tags],
        ] as const).filter(([, ,enabled])=>enabled).map(([destination,icon])=>
          <Button key={destination} variant="ghost" className={`fms-nav${view===destination?' is-active':''}`} aria-label={t[destination]} aria-current={view===destination?'page':'false'} onPress={()=>onView(destination)}><Icon icon={icon}/><span>{t[destination]}</span></Button>
        )}
      </ScrollShadow>
    </nav>
  </>;
}
