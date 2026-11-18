import {Button, Dropdown, Label} from '@heroui/react';
import {AddCircleIcon, ArrowDown01Icon, FolderAddIcon, GridViewIcon, Layout01Icon, ListViewIcon, Upload04Icon,Note01Icon} from '@hugeicons/core-free-icons';
import {Icon, type Messages} from '../../ui';

export type LibraryLayout = 'grid' | 'large-grid' | 'list';
export function LayoutControl({layout, onChange, portal, t}: {layout: LibraryLayout; onChange: (value: LibraryLayout) => void; portal: HTMLDivElement; t: Messages}) {
  return <Dropdown>
    <Button variant="tertiary" size="sm" isIconOnly aria-label={t.layout}><Icon icon={Layout01Icon}/></Button>
    <Dropdown.Popover className="fms-library-choice-menu" placement="bottom end" UNSTABLE_portalContainer={portal}>
      <Dropdown.Menu aria-label={t.layout} selectionMode="single" selectedKeys={[layout]} onAction={key=>{
        if(key==='grid'||key==='large-grid'||key==='list'){onChange(key);}
      }}>
        <Dropdown.Item className="ps-2 pe-7" id="grid" textValue={t.gridView}><Icon icon={GridViewIcon} size={16}/><Label>{t.gridView}</Label><Dropdown.ItemIndicator className="start-auto end-2"/></Dropdown.Item>
        <Dropdown.Item className="ps-2 pe-7" id="large-grid" textValue={t.largeGrid}><Icon icon={Layout01Icon} size={16}/><Label>{t.largeGrid}</Label><Dropdown.ItemIndicator className="start-auto end-2"/></Dropdown.Item>
        <Dropdown.Item className="ps-2 pe-7" id="list" textValue={t.listView}><Icon icon={ListViewIcon} size={16}/><Label>{t.listView}</Label><Dropdown.ItemIndicator className="start-auto end-2"/></Dropdown.Item>
      </Dropdown.Menu>
    </Dropdown.Popover>
  </Dropdown>;
}

export function UploadMenu({upload, folder, portal, t}: {upload: () => void; folder?: (() => void) | undefined; portal: HTMLDivElement; t: Messages}) {
  return <Dropdown>
    <Button size="sm" variant="tertiary" className="fms-soft-action" aria-label={t.uploadMenu}><Icon icon={Upload04Icon}/>{t.uploadMenu}<Icon icon={ArrowDown01Icon} size={16}/></Button>
    <Dropdown.Popover placement="bottom end" UNSTABLE_portalContainer={portal}>
      <Dropdown.Menu aria-label={t.uploadMenu} onAction={key => {if (key === 'file') upload(); else if (key === 'folder') folder?.();}}>
        <Dropdown.Item id="file" textValue={t.upload}><Icon icon={Upload04Icon}/><Label>{t.upload}</Label></Dropdown.Item>
        {folder && <Dropdown.Item id="folder" textValue={t.uploadFolder}><Icon icon={FolderAddIcon}/><Label>{t.uploadFolder}</Label></Dropdown.Item>}
      </Dropdown.Menu>
    </Dropdown.Popover>
  </Dropdown>;
}

export function CreateMenu({createFolder, note, portal, t}: {note?:(()=>void)|undefined; createFolder: () => void; portal: HTMLDivElement; t: Messages}) {
  return <Dropdown>
    <Button size="sm" variant="tertiary" className="fms-soft-action"><Icon icon={AddCircleIcon}/>{t.create}<Icon icon={ArrowDown01Icon} size={16}/></Button>
    <Dropdown.Popover placement="bottom end" UNSTABLE_portalContainer={portal}>
      <Dropdown.Menu aria-label={t.create} onAction={key => {if(key==='create-folder')createFolder();else if(key==='note')note?.();}}>
        <Dropdown.Item id="create-folder" textValue={t.newFolder}><Icon icon={FolderAddIcon}/><Label>{t.newFolder}</Label></Dropdown.Item>
        {note&&<Dropdown.Item id="note" textValue={t.newNote}><Icon icon={Note01Icon}/><Label>{t.newNote}</Label></Dropdown.Item>}
      </Dropdown.Menu>
    </Dropdown.Popover>
  </Dropdown>;
}

/** The compact menu preserves the same capability-gated callbacks as the expanded pair. */
export function LibraryCreationActions({upload,uploadFolder,createFolder,note,portal,t}: {upload?:(()=>void)|undefined;uploadFolder?:(()=>void)|undefined;createFolder:()=>void;note?:(()=>void)|undefined;portal:HTMLDivElement;t:Messages}) {
 return <>
  <div className="fms-creation-expanded">{upload&&<UploadMenu upload={upload} folder={uploadFolder} portal={portal} t={t}/>}<CreateMenu createFolder={createFolder} note={note} portal={portal} t={t}/></div>
  <div className="fms-creation-compact"><Dropdown>
   <Button size="sm" variant="tertiary" className="fms-soft-action" aria-label={t.create}><Icon icon={AddCircleIcon}/>{t.create}<Icon icon={ArrowDown01Icon} size={16}/></Button>
   <Dropdown.Popover placement="bottom end" UNSTABLE_portalContainer={portal}><Dropdown.Menu aria-label={t.create} onAction={key=>{if(key==='upload')upload?.();else if(key==='upload-folder')uploadFolder?.();else if(key==='create-folder')createFolder();else if(key==='note')note?.();}}>
    {upload&&<Dropdown.Item id="upload" textValue={t.upload}><Icon icon={Upload04Icon}/><Label>{t.upload}</Label></Dropdown.Item>}
    {uploadFolder&&<Dropdown.Item id="upload-folder" textValue={t.uploadFolder}><Icon icon={FolderAddIcon}/><Label>{t.uploadFolder}</Label></Dropdown.Item>}
    <Dropdown.Item id="create-folder" textValue={t.newFolder}><Icon icon={FolderAddIcon}/><Label>{t.newFolder}</Label></Dropdown.Item>
    {note&&<Dropdown.Item id="note" textValue={t.newNote}><Icon icon={Note01Icon}/><Label>{t.newNote}</Label></Dropdown.Item>}
   </Dropdown.Menu></Dropdown.Popover>
  </Dropdown></div>
 </>;
}
